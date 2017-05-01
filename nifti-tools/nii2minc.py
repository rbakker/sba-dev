# Rembrandt Bakker, June 2014

import argparse, sys, re
import os.path as op
import numpy
import nibabel

SliceDirs = {'x':'Left-Right','y':'Posterior-Anterior','z':'Inferior-Superior'}

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
      description="Use nibabel to convert NIfTI1 file (.nii, .nii.gz) to MINC (.mnc)",
      formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('input',type=str, help="Input NIfTI1 file (.nii or .nii.gz)")
  parser.add_argument('output',type=str, nargs='?', help="Output MINC file (.mnc)")
  return parser

def parse_arguments(raw=None):
    """ Apply the argument parser. """
    args = argument_parser().parse_args(raw)
    if not args.output:
        args.output = re.sub('(\.nii\.gz|\.nii)$','mnc',args.input)
    print 'Output MINC file: {}'.format(args.output)
    return args

         
def run(args):
    try:
        nii = nibabel.load(args.input)
        img = numpy.squeeze(nii.get_data())
        img_min = numpy.amin(img)
        img_max = numpy.amax(img)
        print 'Image type: {} {}-{}'.format(img.dtype,img_min,img_max)
        hdr = nii.get_header()
        q = hdr.get_best_affine();
        ornt = nibabel.io_orientation(q)
        print 'Orientation: {}'.format(ornt)

        from StringIO import StringIO
        file_map = nibabel.MincImage.make_file_map()
        file_map['image'].fileobj = StringIO()
        
        print sorted(file_map)
        mnc = nibabel.MincImage(img,q)
        mnc.file_map = file_map
        mnc.to_file_map()
    except:
        print "Unexpected error:", sys.exc_info()[0]
        raise


if __name__ == '__main__':
    args = parse_arguments()
    run(args)
