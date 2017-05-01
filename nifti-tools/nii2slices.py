# Rembrandt Bakker, Dhruv Kohli, Piotr Majka, June 2014

import argparse, sys, os
import os.path as op
import re, json
import numpy, scipy.misc
import niitools as nit
import jsonrpc2

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--nifti_src', type=str, help="Input Nifti file", required=True)
  parser.add_argument('-o','--out', type=str, help="Output folder (existing files will only be overwritten if --replace is set)", required=True)
  parser.add_argument('-d','--slicedir', type=str, default='y', help="Slice direction: 0 or x = Saggital, 1 or y = Coronal, 2 or z = Axial")
  colormapHelp = """
      Colormap supports various formats:
      1. comma separated list of rgb-hex values: #RGB1,#RGB2,...
      2. range of rgb-hex values: #RGB1-#RGB2
      3. constant color with range of alpha values: alpha-#RGB
      4. automatic high-contrast colormap: auto
  """
  parser.add_argument('-c','--colormap', type=str, help=colormapHelp, required=True)
  parser.add_argument('-pctile','--pctile', type=str, help='Rescale data using these min/max percentile values, e.q. "1,99". If omitted, no rescaling is done and image is saved as png.', required=False)
  parser.add_argument('--replace', action='store_true', help="If set, existing png files will be replaced.")
  parser.add_argument('--origin', type=str, help="If set to 'center', the (0,0,0) point is take to be the center of the volume.", default=None)
  parser.add_argument('--reorient', type=str, help="Reorient volume by permuting and flipping axes. Example: '+j+i+k' to permute dimension i and j.", default=None)
  parser.add_argument('--boundingbox-bgcolor', type=str, help="If specified, compute boundingboxes of non-background area and save as boundingbox.json. Use hex-color or 'auto'.", default='')
  parser.add_argument('--count-pixels', action='store_true', help="compute region volumes", required=False)
  parser.add_argument('--jsonrpc2', action='store_true', help="return output as json-rpc 2.0", required=False)
  return parser

def parse_arguments(raw=None):
  global report
  try:
    """ Apply the argument parser. """
    args = argument_parser().parse_args(raw)

    if args.jsonrpc2:
      report = jsonrpc2.rpc_report_class()

    """ Basic argument checking """
    if not op.exists(args.nifti_src):
      raise Exception('Nifti file "{}" not found.'.format(args.nifti_src))

    if args.slicedir.isdigit():
      args.dim = args.slicedir
      if args.slicedir<0 or args.slicedir>3:
        raise Exception('Invalid slice dimension "{}".'.format(args.slicedir))
    else:
      dir2dim = {'x':0,'y':1,'z':2}
      if args.slicedir in dir2dim:
        args.dim = dir2dim[args.slicedir]
      else:
        raise Exception('Invalid slice direction "{}".'.format(args.slicedir))
   
    return args
  except:
    report.fail(__file__)

  
def run(args):
  try:
    print('Input Nifti: '+args.nifti_src)
    print('Colormap to use: '+args.colormap)

    import nibabel
    nii = nibabel.load(args.nifti_src)
    
    # Nifti data is supposed to be in RAS orientation.
    # For Nifti files that violate the standard, the reorient string can be used to correct the orientation.
    if isinstance(args.reorient,str):
      nii = nit.reorient(nii,args.reorient)

    hdr = nii.get_header()
    q = hdr.get_best_affine();
    ornt = nibabel.io_orientation(q)
    print('The orientation is: {}'.format(ornt))
    dims0 = [int(d) for d in nii.shape if d>1]
    dims = list(dims0)
    for i,d in enumerate(ornt):
      dims[i] = dims0[int(d[0])]
    print('The dimensions are: {}'.format(dims))
    sliceDim = args.dim
    numSlices = dims[sliceDim];
    baseName = op.basename(args.nifti_src)
    baseName = re.sub('.gz$', '',baseName)
    baseName = re.sub('.nii$', '',baseName)
    outFolder = args.out
    if not op.exists(outFolder):
      os.makedirs(outFolder)
      print('Created output folder "{}".'.format(outFolder))

    rgbMode = False
    img_dtype = nii.get_data_dtype();
    if len(dims)==4 and dims[3]==3: 
      rgbMode = True
    elif img_dtype.names:
      if len(img_dtype.names)==3:
        rgbMode = 'record'
    rescale =  "pctile" in args and args.pctile != None

    fmt = 'png'
    if rescale or rgbMode:
      fmt = 'jpg'

    filePattern = baseName+'_%04d.{}'.format(fmt)
    filePattern_py = filePattern.replace('_%04d','_{:04d}')

    if args.origin and args.origin.startswith('['):
      try:
        xyz0 = json.loads(args.origin)
        q = hdr.get_best_affine()
        q[0:3,3] = -q[0:3,0:3].dot(xyz0)
        hdr.set_sform(q)
      except:
        raise Exception('Invalid origin "{}".'.format(args.origin))

    # save coordinate system (Right Anterior Superior) information
    rasLimits = nit.rasLimits(hdr)
    
    # Some nii files have no origin set (other than i=0, j=0, k=0)
    # Use the origin parameter to overrule this.
    if args.origin == 'center':
      def ctr(x1,x2): 
        w=x2-x1
        return -w/2,w/2
      rasLimits = [ctr(xx[0],xx[1]) for xx in rasLimits]
        
    with open(op.join(outFolder,'raslimits.json'), 'w') as fp:
      json.dump(rasLimits,fp)
      
    slicePos = (rasLimits[sliceDim][0] + (numpy.arange(0.0,dims[sliceDim])+0.5)*(rasLimits[sliceDim][1]-rasLimits[sliceDim][0])/dims[sliceDim]).tolist()
    with open(op.join(outFolder,'slicepos.json'), 'w') as fp:
      json.dump(slicePos,fp)

    # quit if ALL slices already exist
    if not args.replace:
      done = True
      for i in range(0,numSlices):
        outFile = filePattern_py.format(i)
        fullFile = op.join(outFolder,outFile)
        if not op.exists(fullFile): 
          done = False
          break
      if done:
        result = {
          'filePattern':op.join(outFolder,filePattern),
          'rasLimits':rasLimits
        }
        report.success(result)
        return
    
    # load image, it is needed 
    img = nii.get_data()
    img = nibabel.apply_orientation(img,ornt)
    img = numpy.squeeze(img)
    if rgbMode == 'record': img = nit.record2rgb(img)
    
    print('Nifti image loaded, shape "{}",data type "{}"'.format(dims,img.dtype))

    maxSlices = 2048;
    if numSlices>maxSlices:
      raise Exception('Too many slices (more than '+str(maxSlices)+')');

    if not rgbMode:
      minmax = nit.get_limits(img)
      if rescale:                
        minmax = nit.get_limits(img,args.pctile)
      print('minmax {}, rescale {}'.format(minmax,rescale))
      index2rgb = nit.parse_colormap(args.colormap,minmax)

      if isinstance(index2rgb,dict):
        rgbLen = len(index2rgb[index2rgb.keys()[0]])
      else:
        rgbLen = len(index2rgb[0])

      # save index2rgb
      if not rescale:
        if isinstance(index2rgb,dict):
          index2rgb_hex = {index:'{:02X}{:02X}{:02X}'.format(rgb[0],rgb[1],rgb[2]) for (index,rgb) in index2rgb.iteritems()}
        else:
          index2rgb_hex = ['{:02X}{:02X}{:02X}'.format(rgb[0],rgb[1],rgb[2]) for rgb in index2rgb]
        with open(op.join(outFolder,'index2rgb.json'), 'w') as fp:
          json.dump(index2rgb_hex,fp)
    elif rgbMode:
      grayscale = img.dot(numpy.array([0.2989,0.5870,0.1140]))
      minmax = nit.get_limits(grayscale)
      if rescale:                
        minmax = nit.get_limits(grayscale,args.pctile)
      rescale = True
      rgbLen = 3
      index2rgb = False
    else:
      rescale = False
      rgbLen = 3
      index2rgb = False

    bbg = args.boundingbox_bgcolor
    if bbg is '': bbg = False
    if bbg:
      boundingBox = {}
      boundingBoxFile = op.join(outFolder,'boundingbox.json')
      if op.exists(boundingBoxFile):
        with open(boundingBoxFile, 'r') as fp:
          boundingBox = json.load(fp)

    pxc = args.count_pixels
    if pxc:
      pixCount = {};
      pixCountFile = op.join(outFolder,'pixcount.json')
      if op.exists(pixCountFile):
        with open(pixCountFile, 'r') as fp:
          pixCount = json.load(fp)

    for i in range(0,numSlices):
      outFile = filePattern_py.format(i)
      fullFile = op.join(outFolder,outFile)
      if op.exists(fullFile):
        if i==0:
          print('image {}{} already exists as {}-file "{}".'.format(sliceDim,i,fmt,fullFile))
        if not args.replace: 
          continue
      slc = nit.get_slice(img,sliceDim,i)
      print ('slice shape {}'.format(slc.shape))

      if pxc:
        labels = numpy.unique(slc)
        cnt = {}
        for b in labels:
          cnt[str(b)] = numpy.count_nonzero(slc == b)
        pixCount[i] = cnt
        
      if index2rgb:
        slc = nit.slice2rgb(slc,index2rgb,rescale,minmax[0],minmax[1])
      elif rescale:
        slc = nit.rgbslice_rescale(slc,minmax[0],minmax[1])

      # DEPRECATED, RECORD2RGB conversion is done on 3d image
      #if rgbMode=='record':
      #  # create 3rd dimension from rgb record
      #  slc = record2rgb(slc)

      # Save image
      ans = scipy.misc.toimage(slc).save(op.join(outFolder,outFile))

      if bbg:
        if(bbg == "auto"):
          # do this only once
          bgColor = nit.autoBackgroundColor(slc)
          print('boundingbox auto bgColor is {}'.format(bgColor))
        else:
          bgColor = nit.hex2rgb(bgg)
        mask = nit.imageMask(slc,[bgColor])
        print 'mask shape {} {}'.format(bgColor,mask.shape)
      
        ## bounding box
        nonzero = numpy.argwhere(mask)
        #print 'nonzero {}'.format(nonzero)
        if nonzero.size>0:
          lefttop = nonzero.min(0)[::-1] # because y=rows and x=cols
          rightbottom = nonzero.max(0)[::-1]
          bb = lefttop.tolist()
          bb.extend(rightbottom-lefttop+(1,1))
          boundingBox[i] = bb
      
      if i==0:
        print('image {}{} saved to {}-file "{}".'.format(sliceDim,i,fmt,fullFile))
      
    if bbg:
      if len(boundingBox)>0:
        bb0 = boundingBox.itervalues().next()
        xyMin = [bb0[0],bb0[1]]
        xyMax = [bb0[0]+bb0[2],bb0[1]+bb0[3]]
        for bb in boundingBox.itervalues():
          if bb[0]<xyMin[0]: xyMin[0] = bb[0]
          if bb[1]<xyMin[1]: xyMin[1] = bb[1]
          if bb[0]+bb[2]>xyMax[0]: xyMax[0] = bb[0]+bb[2]
          if bb[1]+bb[3]>xyMax[1]: xyMax[1] = bb[1]+bb[3]
        boundingBox['combined'] = [xyMin[0],xyMin[1],xyMax[0]-xyMin[0],xyMax[1]-xyMin[1]]
      with open(boundingBoxFile, 'w') as fp:
        json.dump(boundingBox,fp)
      
    if pxc:
      with open(pixCountFile, 'w') as fp:
        json.dump(pixCount,fp)

    result = {
      'filePattern':op.join(outFolder,filePattern),
      'rasLimits':rasLimits
    }
    report.success(result)
  except:
    report.fail(__file__)

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
