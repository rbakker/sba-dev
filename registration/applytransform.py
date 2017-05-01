# Rembrandt Bakker, December 2014

import argparse, sys, os
import os.path as op
import subprocess
import re, json
import numpy,nibabel,tempfile
sys.path.append(op.join(op.dirname(__file__),'../nifti-tools'))
import jsonrpc2

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--inp', type=str, help="Input volume to be transformed", required=True)
  parser.add_argument('-o','--out', type=str, help="Output directory", required=True)
  parser.add_argument('-tp','--tpfile', type=str, help="Transform parameter file", required=True)
  parser.add_argument('-prog','--program', type=str, default="transformix", help="Name of transformation software package (default: transformix)", required=False)
  parser.add_argument('-m','--multilabel', action='store_true', help="Treat the input file as a multi-label volume", required=False)
  parser.add_argument('--replace', action='store_true', help="If set, existing transformation parameters will be replaced", required=False)
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
      raise Exception('Nifti file "{}" not found.'.format(args.inp))

    if not op.exists(args.tpfile):
      raise Exception('Nifti file "{}" not found.'.format(args.tpfile))

    return args
  except:
    report.fail(__file__)

def applyTransformation(infile,outdir,program,tpfile,multilabel,replace=False):
  if program != 'transformix':
    raise Exception('Software other than "transformix" not supported, you specified "{}"'.format(program))
  
  if not op.exists(outdir):
    os.makedirs(outdir)
  resultFile = op.join(outdir,'result.nii')
  done = op.isfile(resultFile)
  if not replace and done:
    cmdline = None
    ans = 'Skipping transformation of "{}"; result already exists.'.format(infile)
  else:
    if 0 and multilabel:
      bgLabel = 0
      print 'Processing multi-label volume'
      nii = nibabel.load(infile)
      nii = nibabel.as_closest_canonical(nii)
      hdr = nii.get_header()
      q = hdr.get_best_affine()
      img = numpy.squeeze(nii.get_data())
      dtype = img.dtype
      labels = numpy.unique(img)
      (fp,tmpFile) = tempfile.mkstemp(suffix='.nii.gz',prefix='SBA_')
      tmpDir = tempfile.mkdtemp(prefix='SBA_')
      maxProb = None
      bestMatch = None
      for r in labels:
        mask = img==r
        nibabel.nifti1.save(nibabel.nifti1.Nifti1Image(mask.astype(numpy.float),q),tmpFile)
        cmd = [
          program,
          '-in',tmpFile,
          '-out',tmpDir,
          '-tp',tpfile
        ]
        cmdline = " ".join(cmd)
        print 'Command line: {}'.format(cmdline)
        ans = subprocess.check_output(cmd, shell=False, stderr=subprocess.STDOUT)
        resultFile = op.join(tmpDir,'result.nii')
        nii = nibabel.load(resultFile)
        img_r = numpy.squeeze(nii.get_data())
        if maxProb==None: 
          maxProb = numpy.zeros(img_r.shape,numpy.float)
          bestMatch = numpy.zeros(img_r.shape,dtype)
        mask = img_r>maxProb
        maxProb[mask] = img_r[mask]
        bestMatch[mask] = r
      # save bestMatch as resultFile
      nibabel.nifti1.save(nibabel.nifti1.Nifti1Image(bestMatch,q),resultFile)
    else:
      cmd = [
        program,
        '-in',infile,
        '-out',outdir,
        '-tp',tpfile
      ]
      cmdline = " ".join(cmd)
      print 'Command line: {}'.format(cmdline)
      ans = subprocess.check_output(cmd, shell=False, stderr=subprocess.STDOUT)

  return {
    'cmdline': cmdline,
    'outfile': resultFile,
    'cmdlog': ans
  }
  
def run(args):
  try:
    result = applyTransformation(
      args.inp,
      args.out,
      args.program,
      args.tpfile,
      args.multilabel,
      replace = args.replace
    )
    report.success(result)
  except:
    report.fail(__file__)

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
