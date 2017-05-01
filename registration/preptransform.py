# Rembrandt Bakker, December 2014

import argparse, sys, os
import os.path as op
import subprocess
import re, json
import numpy
sys.path.append(op.join(op.dirname(__file__),'../nifti-tools'))
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
  parser.add_argument('-prog','--program', type=str, default="elastix", help="Name of registration software package (default: elastix)", required=False)
  parser.add_argument('-p','--paramfiles', type=str, help="Parameter files (comma separated)", required=True)
  parser.add_argument('--replace', action='store_true', help="If set, existing transformation parameters will be replaced")
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

def prepareTransformation(moving,fixed,via,outdir,program,paramfiles,replace=False):
  if program != 'elastix':
    raise Exception('Software other than "elastix" not supported, you specified "{}"'.format(program))
  
  if not op.exists(outdir):
    os.makedirs(outdir)
  cmd = [
    program,
    '-f',fixed,
    '-m',moving,
    '-out',outdir
  ]
  done = True;
  tpfiles = []
  for i,p in enumerate(paramfiles):
    cmd.append('-p')
    cmd.append(p)
    tpfile = op.join(outdir,'TransformParameters.{}.txt'.format(i))
    if not op.isfile(tpfile):
      done = False
    tpfiles.append(tpfile)
  
  cmdline = " ".join(cmd)
  if not done or replace:
    ans = subprocess.check_output(cmd, shell=False, stderr=subprocess.STDOUT)
  else:
    ans = 'Skipping preparation of "{}"; transformation parameters already exist.'.format(outdir)
  return {
    'cmdline': cmdline,
    'program': 'transformix',
    'tpfiles': tpfiles,
    'cmdlog': ans
  }
  
def run(args):
  try:
    result = prepareTransformation(
      args.moving,
      args.fixed,
      args.via,
      args.outdir,
      args.program,
      args.paramfiles.split(','),
      replace = args.replace
    )
    report.success(result)
  except:
    report.fail(__file__)

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
