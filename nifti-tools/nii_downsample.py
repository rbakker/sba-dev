# Rembrandt Bakker, November 2014

import argparse, sys, numpy, json
import os,os.path as op
import re
import numpy
import jsonrpc2
import niitools as nit

report = jsonrpc2.report_class()
commandline = ''

def argument_parser():
    """ Define the argument parser and return the parser object. """
    parser = argparse.ArgumentParser(
        description="""
            Downsamples a nifti volume by an integer factor in all three dimensions.
        """,
        formatter_class=argparse.RawTextHelpFormatter)
    parser.add_argument('-i','--inp', type=str, help="Input Nifti file", required=True)
    parser.add_argument('-o','--out', type=str, help="Output downsampled Nifti file", required=False)
    parser.add_argument('-f','----factor', type=int, default="2", help="Downsample factor (integer)", required=False)
    parser.add_argument('--reorient', type=str, help="Reorient volume by permuting and flipping axes. Example: '+j+i+k' to permute dimension i and j.", default=None)
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

        if not args.out:
            args.out = re.sub('\.nii($)|\.nii(\.gz$)','_downsampled.nii.gz',args.inp)
        
        return args
    except:
        report.fail(__file__)
        


def run(args):
    try:
        import nibabel
        nii = nibabel.load(args.inp)
        
        # Nifti data is supposed to be in RAS orientation.
        # For Nifti files that violate the standard, the reorient string can be used to correct the orientation.
        if isinstance(args.reorient,str):
            nii = nit.reorient(nii,args.reorient)
        
        nii = nibabel.as_closest_canonical(nii)
        hdr = nii.get_header()
        q = hdr.get_best_affine()
        img = numpy.squeeze(nii.get_data())
        dims = img.shape
        f = int(args.factor)
        
        outFile = args.out
        if op.isfile(outFile):
            print 'Skipping downsample by factor {}. Output file {} exists.'.format(f,outFile)          
        else:
            (img,q) = nit.downsample3d(img,q,f)
            if img.shape[-1]==3:
                img3 = img
                img = numpy.zeros(img3.shape[:-1],dtype=[('R', 'u1'), ('G', 'u1'), ('B', 'u1')])
                img['R'] = img3[:,:,:,0]
                img['G'] = img3[:,:,:,1]
                img['B'] = img3[:,:,:,2]
            nii = nibabel.Nifti1Image(img,q)
            # nii.set_sform(None,0) # set the sform code to 0 'unknown', to make it viewable in MRIcron 
            nibabel.nifti1.save(nii,outFile)
            print 'Saved downsampled image to "{}" '.format(outFile)

        result = {
            'shape': (numpy.array(dims)/f).tolist(),
            'filesize': op.getsize(outFile)
        }
        report.success(result)
    except:
        report.fail(__file__)
    
if __name__ == '__main__':
  args = parse_arguments()
  run(args)
