import argparse, sys
import os.path as op
import deepzoom, PIL
import jsonrpc2, json

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description="""
      Downsamples a nifti volume by an integer factor in all three dimensions.
    """,
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--inp', type=str, help="Input (large) 2d image file", required=True)
  parser.add_argument('-o','--out', type=str, help="Output deepzoom folder", required=False)
  parser.add_argument('--replace', action='store_true', help="If set, existing files will be replaced")
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
    if not op.exists(args.inp):
      raise Exception('Image file "{}" not found.'.format(args.inp))

    return args
  except:
    report.fail(__file__)
        
def img2dzi(infile,outdir):
  # Create Deep Zoom Image pyramid
  creator = deepzoom.ImageCreator(tile_size=254, tile_overlap=1, tile_format="jpg",
                                  image_quality=0.75, resize_filter="bicubic")
  creator.create(infile,outdir)
  return [creator.descriptor.width,creator.descriptor.height];

def run(args):
  try:
    if op.exists(args.out):
      if not args.replace: 
        print('Skipping creation of deep zoom image "{}", it already exists.'.format(args.out))
        img = PIL.Image.open(args.inp)
        result = {
          'status': 'Skipped',
          'shape': img.size
        }
        
        report.success(result)        
        return

    wh = img2dzi(args.inp,args.out)
    
    result = {
      'status': 'Done',
      'shape': wh
    }
    report.success(result)
  except:
    report.fail(__file__)
    
if __name__ == '__main__':
  args = parse_arguments()
  run(args)
