import argparse, sys, numpy, json
import os.path as op
import numpy
import jsonrpc2
from PIL import Image
import images2gif
import niitools as nit

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description="""
      Creates an animated gif image from a stack of images. 
      Automatically detects background color and boundingbox of the non-background content.
    """,
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--inp', type=str, help="Input image files, in printf format (such as image%04d.png)", required=True)
  parser.add_argument('-r','--range', type=str, help="Input range, comma separated array of 2 or 3 integers: start, end, step", required=True)
  parser.add_argument('-o','--out', type=str, help="Output animated gif", required=False)
  parser.add_argument('-t','--time-step', type=int, default=40, help="Time step in ms (default 40)", required=False)
  parser.add_argument('--replace', action='store_true', help="If set, existing animated gif will be replaced")
  parser.add_argument('--jsonrpc2', action='store_true', help="return output as json-rpc 2.0", required=False)
  return parser


def parse_arguments(raw=None):
  global report
  try:
    """ Apply the argument parser. """
    args = argument_parser().parse_args(raw)

    if args.jsonrpc2:
      report = jsonrpc2.rpc_report_class()

    try:
      fromto = map(int,args.range.split(','))
    except:
      try:
        fromto = json.loads(args.range)
      except:
        report.error('Cannot parse range "'+args.range+'"')
    if len(fromto) <= 2: 
      fromto.append(1)
    args.input_range = range(fromto[0],fromto[1]+1,fromto[2]);

    """ Basic argument checking """
    for r in args.input_range:
      fname = args.inp % (r)
      if not op.exists(fname):
        raise Exception('Image file "{}" not found.'.format(fname))

    return args
  except:
    report.fail(__file__)
        
def run(args):
  try:
    if op.exists(args.out):
      if not args.replace: 
        print('Skipping creation of image "{}", it already exists.'.format(args.out))
        result = {
          'Status': 'Skipped'
        }
        report.success(result)        
        return

    stack = []
    lefttop = [1000000,1000000]
    rightbottom = [0,0]
    bgColor = None
    for r in args.input_range:
      fname = args.inp % (r)
      img = numpy.array(Image.open(fname))
      if bgColor is None: bgColor = img[0,0]
      mask = nit.imageMask(img,[bgColor])
      nonzero = numpy.argwhere(mask)
      if nonzero.size>0:
        lt = nonzero.min(0)
        rb = nonzero.max(0)
        if lt[0]<lefttop[0]: lefttop[0] = lt[0]
        if lt[1]<lefttop[1]: lefttop[1] = lt[1]
        if rb[0]>rightbottom[0]: rightbottom[0] = rb[0]
        if rb[1]>rightbottom[1]: rightbottom[1] = rb[1]
      stack.append(img)
    for i in range(0,len(stack)):
      stack[i] = stack[i][lefttop[0]:rightbottom[0],lefttop[1]:rightbottom[1]]
    
    images2gif.writeGif(
      args.out,
      stack,
      duration=args.time_step/1000.0,
      dither=0
    )

    result = {
      'Status': 'Done'
    }
    report.success(result)
  except:
    report.fail(__file__)
    
if __name__ == '__main__':
  args = parse_arguments()
  run(args)
