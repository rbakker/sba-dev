import argparse, os, sys, subprocess
import os.path as op
import jsonrpc2
import niitools as nit
import scipy.misc

report = jsonrpc2.report_class()

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
  #parser.add_argument('--convex-hulls', action='store_true', help="If set, the png images are converted to convex hulls before svg conversion")
  parser.add_argument('--jsonrpc2', action='store_true', help="return output as json-rpc 2.0", required=False)
  return parser

def parse_arguments(raw=None):
  """ Apply the argument parser. """
  args = argument_parser().parse_args(raw)
  if args.jsonrpc2:
    global report
    report = jsonrpc2.rpc_report_class()

  """ Basic argument checking """
  if not op.exists(args.png_src):
    report.error('PNG folder "{}" not found.'.format(args.png_src))
    report.fail(__file__)

  return args

def vectorize(report,pngFolder,svgFolder,replace,curve_tolerance,line_tolerance,bgColor='auto'):
  converted = 0;
  skipped = 0;
  print('Input PNG Folder is "{}".'.format(pngFolder))
  if not(op.exists(svgFolder)):
    os.makedirs(svgFolder)
    print('Created output folder "{}".'.format(svgFolder))

  includedExtensions=['png']
  fileNames = [fn for fn in os.listdir(pngFolder) if any([fn.endswith(ext) for ext in includedExtensions])]

  # iterate over all png file names
  for pngFile in fileNames:
    baseName = op.splitext(pngFile)[0]
    svgFile = baseName+'.svg'
    outputFile =  op.join(svgFolder,svgFile)
    if op.exists(outputFile):
      if not replace: 
        print('svg-file "{}" already exists.'.format(svgFile))
        skipped += 1
        continue
    
    # background color
    inputFile = op.join(pngFolder,pngFile)
    if (bgColor == 'auto'):
      img = scipy.misc.imread(inputFile)
      bgColor = nit.autoBackgroundColor(img)
      print('bgColor is {}'.format(bgColor))
    elif bgColor != '':
      bgColor = nit.hex2rgb(bgColor)
    print('background color {}.'.format(bgColor))
    
    # generate and save svg with mindthegap
    try:
      prog = op.abspath(op.join(op.dirname(__file__),'../mindthegap/bin/mindthegap'))
      cmd = [prog,"-i",inputFile,"-o",outputFile,"-t",str(curve_tolerance),"-s",str(line_tolerance)]
      if bgColor is not None: 
        bgColor = nit.rgb2hex(bgColor,'#')
        cmd.extend(["-c",bgColor])
      print('Calling:\n'+' '.join(cmd)+'\n')
      ans = subprocess.check_output(cmd, shell=False, stderr=subprocess.STDOUT)
      converted += 1
    except subprocess.CalledProcessError as e:
      msg = 'Subprocess "'+e.cmd[0]+'" returned code '+str(e.returncode)+'.\nCommand: "'+' '.join(e.cmd)+'"\nMessage: "'+e.output+'"'
      report.error(msg)
      raise

    print('vector image saved to svg file "{}".'.format(svgFile))
    
  return {'converted':converted,'skipped':skipped}

def run(args):
  try:
    result = vectorize(
      report,
      args.png_src,
      args.svg_dest,
      args.replace,
      args.curve_tolerance,
      args.line_tolerance,
      args.bg_color
    )
    report.success(result);
  except:
    report.fail(__file__)


if __name__ == '__main__':
  args = parse_arguments()
  run(args)
