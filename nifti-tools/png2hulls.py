import argparse, os, sys, subprocess, tempfile
import os.path as op
import scipy.misc
import numpy
from skimage.morphology import convex_hull_image
import niitools as nit
import jsonrpc2
import png2svg

import math

report = jsonrpc2.report_class()

def unit_tests():
  raw = [
    '-i','../test_png',
    '-o','../test_hulls',
    '-c','',
    '--replace'
  ]
  args = parse_arguments(raw)
  run(args)
  
def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--png_src', type=str, help="Input PNG folder", required=True)
  parser.add_argument('-o','--svg_dest', type=str, help="Output SVG folder", required=True)
  parser.add_argument('-t','--curve-tolerance', type=float, default=1.5, help="Curve tolerance value used by mindthegap (max. squared distance between any edge point and fitted curve)")
  parser.add_argument('-s','--line-tolerance', type=float, default=0.5, help="Straight line tolerance value used by mindthegap (max. squared distance between any edge point and fitted straight line)")
  parser.add_argument('-c','--bg-color', type=str, help="Background color", default="auto")
  parser.add_argument('--replace', action='store_true', help="If set, existing svg files will be replaced")
  parser.add_argument('--jsonrpc2', action='store_true', help="return output as json-rpc 2.0", required=False)
  return parser

def parse_arguments(raw=None):
  """ Apply the argument parser. """
  args = argument_parser().parse_args(raw)

  if args.jsonrpc2:
    global report
    report = jsonrpc2.rpc_report_class()

  """ Basic argument checking """
  args.useFilePattern = False
  if not op.exists(args.png_src):
    print 'PNG folder or pattern "{}" not found.'.format(args.png_src)
    exit(1)

  return args

def run(args):
  try:
    skipped = 0
    print('Input PNG Folder: '+args.png_src)
    pngFolder = args.png_src
    svgFolder = args.svg_dest

    if not(op.exists(svgFolder)):
      os.makedirs(svgFolder)
      print 'Created output folder "{}".'.format(svgFolder)

    # generate hulls in temp dir
    tmpdir = tempfile.mkdtemp(prefix='hull')
    includedExtensions=['png']
    fileNames = [fn for fn in os.listdir(pngFolder) if any([fn.endswith(ext) for ext in includedExtensions])]
    for pngFile in fileNames:
      svgFile = op.splitext(pngFile)[0]+'.svg'
      outputFile =  op.join(svgFolder,svgFile)
      if op.exists(outputFile):
        if not args.replace: 
          print('Skipping svg-hull "{}", it already exists.'.format(svgFile))
          skipped += 1
          continue

      img = scipy.misc.imread(op.join(pngFolder,pngFile))
      # ignore alpha
      if img.shape[-1]==4:
          if len(img.shape)==3:        
              img = img[:,:,0:3]
          elif len(img.shape)==4:
              img = img[:,:,:,0:3]
      if(args.bg_color == "auto"):
        bgColor = nit.autoBackgroundColor(img)
        print('bgColor is {}'.format(bgColor))
      else:
        bgColor = nit.hex2rgb(args.bg_color)
        if img.shape[-1] == 4: bgColor.append(255)

      mask = nit.imageMask(img,[bgColor])
      print 'mask shape {}'.format(mask.shape)
      
      hullFile = op.join(tmpdir,pngFile)
      if numpy.any(mask):
        print 'Computing convex hull for image "{}"'.format(hullFile)
        hull = convex_hull_image(mask)
      else:
        hull = mask
      scipy.misc.toimage(hull).save(hullFile)

      print('vector image saved to svg file "{}".'.format(svgFile))
      
    # do the svg conversion
    result = png2svg.vectorize(report,tmpdir,svgFolder,args.replace,args.curve_tolerance,args.line_tolerance,bgColor='#000000')
    
    # cleanup
    for fn in os.listdir(tmpdir):
        os.remove(op.join(tmpdir,fn))
    os.rmdir(tmpdir)
    
    result['skipped'] = skipped
    report.success(result)
  except:
    report.fail(__file__)

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
