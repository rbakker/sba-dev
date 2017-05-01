# Rembrandt Bakker, Dhruv Kohli, Piotr Majka, June 2014

import argparse, sys, os, numpy
import os.path as op
import nibabel
import jsonrpc2
import nrrd


def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('nifti_src', type=str, help="Input Nifti file")
  return parser

def parse_arguments(raw=None):
  """ Apply the argument parser. """
  args = argument_parser().parse_args(raw)

  """ Basic argument checking """
  if not op.exists(args.nifti_src):
    print 'Nifti file "{}" not found.'.format(args.nifti_src)
    exit(0)

  return args

def run(args):
  try:
    print('Input Nifti: '+args.nifti_src)
    nii = nibabel.load(args.nifti_src)
    img = numpy.squeeze(nii.get_data())
    nrrd_dest = args.nifti_src.replace('.gz','').replace('.nii','.nrrd')
    print('Output NRRD: '+nrrd_dest)
    nrrd.write(nrrd_dest,img)
  except:
    report = jsonrpc2.report_class()
    report.failReport(__file__,1)

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
