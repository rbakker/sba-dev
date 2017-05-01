import argparse,time
import numpy,nibabel
import json,jsonrpc2,re
import os.path as op

report = jsonrpc2.report_class()

# image must be 3d,
# filter must be symmetric and have odd number of values,
# [1,8,1] leaves island pixels intact, i.e. has no effect.
# [1,5,1] preserves single pixel horizontal or vertical lines
# so [1,4,1] will eliminate single pixel lines

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--nifti-in', type=str, help="Input Nifti file", required=True)
  parser.add_argument('-o','--nifti-out', type=str, help="Output Nifti file", required=True)
  parser.add_argument('-f','--filter', type=str, help="Filter coefficients, must have odd length and be symmetric (json)", default='[1,4,1]')
  parser.add_argument('-b','--bg', type=str, help="Background colors to ignore (json)", default='[]')
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
    if not op.exists(args.nifti_in):
      raise Exception('Nifti file "{}" not found.'.format(args.nifti_in))
      
    try:
      args.filter = json.loads(args.filter)
      if len(args.filter)%2 != 1:
        report.error('invalid filter {}; length must be odd.'.format(args.filter))
        report.fail(__file__)
    except ValueError:
      tmp = re.split('[, ]+',args.filter)
      args.filter = [float(s) for s in tmp]
    try:
      args.ignore = json.loads(args.bg)
    except:
      raise
    return args
  except:
    report.fail(__file__)


def multilabelsmooth(img,filter,ignore=[]):
  r = len(filter)//2
  newimg = numpy.zeros_like(img)
  sz = img.shape
  tp = img.dtype
  for j in range(0,sz[1]):
    maxslice = numpy.zeros([sz[0],sz[2]],'double')
    newslice = numpy.zeros([sz[0],sz[2]],tp)
    jMin = max([j-r,0])
    jMax = min([j+r,sz[1]-1])
    # working with the subvolume greatly reduces memory load
    subvolume = img[:,jMin:jMax+1,:]
    subfilter = filter[r+jMin-j:r+jMax+1-j]
    regions = numpy.unique(subvolume)
    for g in regions:
      if g in ignore: continue
      # numpy.dot: For N dimensions it is a sum product over the last axis of a and the second-to-last of b
      filteredslice0 = numpy.dot(subfilter,subvolume==g)
      # filter over i-dimension
      filteredslice = numpy.zeros_like(filteredslice0)
      for i,coeff in enumerate(filter):
        d = i-r
        if d<0:
          filteredslice[-d:,:] += coeff*filteredslice0[:d,:]
        elif d>0:
          filteredslice[:-d,:] += coeff*filteredslice0[d:,:]
        else:
          filteredslice += coeff*filteredslice0
      filteredslice0 = filteredslice
      # filter over k-dimension
      filteredslice = numpy.zeros_like(filteredslice0)
      for k,coeff in enumerate(filter):
        d = k-r
        if d<0:
          filteredslice[:,-d:] += coeff*filteredslice0[:,:d]
        elif d>0:
          filteredslice[:,:-d] += coeff*filteredslice0[:,d:]
        else:
          filteredslice += coeff*filteredslice0
      maxslice = numpy.maximum(maxslice,filteredslice)
      newslice[numpy.logical_and(maxslice==filteredslice,maxslice>0)] = g
    newimg[:,j,:] = newslice
  return newimg

def run(args):
  try:
    t0 = time.time()
    nii = nibabel.load(args.nifti_in)
    hdr = nii.get_header()
    img = numpy.squeeze(nii.get_data())
    img = multilabelsmooth(img,args.filter,args.ignore)
    nibabel.nifti1.save(nibabel.nifti1.Nifti1Image(img,hdr.get_sform()),args.nifti_out)
    t1 = time.time()
    print 'multilabelsmooth finished in {} s'.format(t1-t0)
    report.success()
  except:
    report.fail(__file__)


if __name__ == '__main__':
  args = parse_arguments()  
  run(args)
