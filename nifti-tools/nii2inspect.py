# Rembrandt Bakker, June 2014
import argparse, sys, os, numpy, subprocess
sys.path.extend('../fancypipe')
import os.path as op
import scipy
import re
import json
import niitools

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
      description="""
          Colormap supports various formats:
          1. comma separated list of rgb-hex values: #RGB1,#RGB2,...
          2. range of rgb-hex values: #RGB1-#RGB2
          3. constant color with range of alpha values: alpha-#RGB
      """,
      formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-lr','--layers', type=json.loads, help="Input json struct with fields 'file','title','colormap','pctile'", action='append')
  parser.add_argument('-o','--out', type=str, help="Html output file", required=True)
  parser.add_argument('-oi','--out-images', type=str, help="Image output folder", required=False)
  parser.add_argument('-sx','--slices_x', type=str, help="Slices in the x-dimension, start%:step%:stop%", required=False)
  parser.add_argument('-sy','--slices_y', type=str, help="Slices in the y-dimension, start%:step%:stop%", required=False)
  parser.add_argument('-sz','--slices_z', type=str, help="Slices in the z-dimension, start%:step%:stop%", required=False)
  return parser

def parse_arguments(raw=None):
    """ Apply the argument parser. """
    args = argument_parser().parse_args(raw)
    print '{}'.format(args)
    try:
        print "layer {}".format(args.layers)

        """ Basic argument checking """
        for lr in args.layers:
            if not op.exists(lr["file"]):
                raise Exception('Nifti file "{}" not found.'.format(lr["file"]))

        args.sliceRangePct = [[],[],[]]
        for d in [0,1,2]:
            dim = ['x','y','z'][d]
            s = getattr(args,'slices_'+dim)
            if s:
                s = s.split(':');
                args.sliceRangePct[d] = [int(r.rstrip('%')) for r in s]
            else:
                args.sliceRangePct[d] = [0,10,100]
      
    except:
        from fancypipe import FancyReport
        msg = FancyReport.traceback()
        FancyReport.fail(__file__)
    
    return args
             
def run(args):
    print('Layer specification: {}'.format(args.layers))
    try:
        htmlFile = args.out
        if op.isdir(htmlFile):
            htmlFile = op.join(htmlFile,'index.html')
        htmlFolder = op.dirname(htmlFile)
        if args.out_images:
            imgFolder = args.out_images
        else:
            htmlName,htmlExt = op.splitext(op.basename(htmlFile))
            imgFolder = op.join(htmlFolder,htmlName+'_files')
        
        if not(op.exists(htmlFolder)):
            os.makedirs(htmlFolder)
            print 'Created html output folder "{}".'.format(htmlFolder)
        if not(op.exists(imgFolder)):
            os.makedirs(imgFolder)
            print 'Created image output folder "{}".'.format(imgFolder)
        imgFolder = op.realpath(imgFolder)
        scriptDir = op.realpath(op.dirname(__file__))

        parsedLayers = []
        for i,lr in enumerate(args.layers):
            nifti_src = lr["file"]
            if not nifti_src: continue
            baseName = re.sub('(\.nii|\.nii.gz)$','',op.basename(nifti_src))

            import nibabel
            nii = nibabel.load(nifti_src)
            img = numpy.squeeze(nii.get_data())
            hdr = nii.get_header()
            q = hdr.get_best_affine();
            ornt = nibabel.io_orientation(q)
            img = nibabel.apply_orientation(img,ornt)
            dims = img.shape
            print 'Nifti image loaded, data type "{}"'.format(img.dtype)

            if len(dims)==4:
              raise Exception('NIFTI file with RGB color data not supported yet.')

            # apply colormap
            index2rgb = None
            if "colormap" in lr:
                index2rgb = niitools.parse_colormap(lr["colormap"])

            minmax = [None,None]
            rescale =  "pctile" in args
            if rescale:                
                minmax = niitools.get_limits(img,args.pctile)

            fmt = 'png'
            if rescale and rgbLen is 3:
                fmt = 'jpg'
                  
            sliceRange = [[],[],[]]
            for d in [0,1,2]:
                dim = ['x','y','z'][d]
                numSlices = dims[d];
                sliceStep = int(args.sliceRangePct[d][1]*numSlices/100)
                sliceStart = int(args.sliceRangePct[d][0]*(numSlices-1)/100)
                sliceEnd = int(args.sliceRangePct[d][2]*(numSlices-1)/100)
                sliceRange[d] = [sliceStart,sliceStep,sliceEnd]
                for i in range(sliceStart,sliceEnd+1,sliceStep):
                    slice = niitools.get_slice(img,d,i)
                    
                    pngFile = baseName+'_{}{:d}.{}'.format(dim,i,fmt)
                    if index2rgb:
                        slice = niitools.slice2rgb(slice,index2rgb,rescale,minmax[0],minmax[1])

                    # Save image to PNG
                    scipy.misc.toimage(slice).save(op.join(imgFolder,pngFile))
                        
                    if i==sliceStart:
                        print 'image {}{} saved to png file "{}".'.format(dim,i,pngFile)

            pixdim = hdr['pixdim'][1:4]
            imgsize_mm = [
                round(pixdim[0]*dims[0],1),
                round(pixdim[1]*dims[1],1),
                round(pixdim[2]*dims[2],1)
            ]
            print 'Image size in mm {}'.format(imgsize_mm)

            # update parsedLayers
            pl = {
              "name": baseName,
              "ext": fmt,
              "src": nifti_src,
              "imgsize_px": dims,
              "imgsize_mm": imgsize_mm
            }
            if "title" in lr:
                pl["title"] = lr["title"];
            parsedLayers.append(pl);

        inspectFile = '{}/nii_inspect.html'.format(scriptDir);
        with open(inspectFile, 'r') as fp:
            html = fp.read()
            html = html.replace(r"var defaultLayers = [];",
                r"var defaultLayers = {};".format(json.dumps(parsedLayers)))                            
            html = html.replace(r"var defaultSliceRange = [];",
                "var defaultSliceRange = {};".format(json.dumps(sliceRange)))
            html = html.replace(r"var imgDir = '';",
                "var imgDir = '{}/';".format(op.relpath(imgFolder,htmlFolder)))
  
        with open(htmlFile, 'w') as fp:
            fp.write(html)
                
        print 'HTML viewer saved as "{}"'.format(htmlFile)
        
    except:
        print "Unexpected error:", sys.exc_info()[0]
        raise


if __name__ == '__main__':
    args = parse_arguments()
    run(args)
