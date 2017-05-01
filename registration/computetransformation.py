# Rembrandt Bakker, December 2014

import argparse, sys, os
import os.path as op
import re, json
import numpy
import jsonrpc2

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-m','--moving', type=str, help="Moving (nifti) volume", required=True)
  parser.add_argument('-f','--fixed', type=str, help="Fixed (nifti) volume", required=True)
  parser.add_argument('-v','--via', type=str, default=None, help="Via intermediate (nifti) volume", required=False)
  parser.add_argument('-o','--outdir', type=str, help="Output directory", required=True)
  parser.add_argument('-p','--program', type=str, default="elastix", help="Name of registration software package (default: elastix)", required=False)
  parser.add_argument('-par','--paramfile', type=str, help="Parameter file", required=True)
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
    if not op.exists(args.moving):
      raise Exception('Nifti file "{}" not found.'.format(args.moving))

    if not op.exists(args.fixed):
      raise Exception('Nifti file "{}" not found.'.format(args.fixed))

    if args.via and not op.exists(args.via):
      raise Exception('Nifti file "{}" not found.'.format(args.via))

    return args
  except:
    report.fail(__file__)

def register(moving,fixed,via,outdir):
  

  
def run(args):
  try:
    register()
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
