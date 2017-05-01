# Rembrandt Bakker, Dhruv Kohli, Piotr Majka, June 2014
import sys,os
os.chdir(sys.path[0])
sys.path.append('../fancypipe')
sys.path.append('../nifti-tools')
from fancypipe import *
import os.path as op
import json
import numpy,nibabel
from nibabel.affines import apply_affine

class CenterFromImg(FancyTask):
  runParallel = True
  inputs = odict(
    'img', dict(help='the numpy label image volume'),
    'q', dict(help='affine transformation parameters'),
    'b', dict(help='label to find the center of'),
  )  
  def main(self,img,q,voxVol,b):
    tmp = numpy.argwhere(img==b)
    xyz = apply_affine(q,tmp.mean(axis=0)).round(3)
    L = xyz[:,0] < 0
    FANCYDEBUG(L)
    return dict(
      xyz = xyz.tolist(),
      vol = numpy.round(tmp.shape[0]*voxVol,3)
    )
    
class NiftiToCenters(FancyTask):
  inputs = odict(
    ('input_nii', dict(type=assertFile, help="Input Nifti file")),
    ('origin_override', dict(type=assertList, default=None, help="Override origin (i0,j0,k0)")),
    ('index2rgb_json', dict(type=assertFile, default=None, help="Index to rgb map")),
    ('rgbcenters_json', dict(type=str, help="Output rgb center file")),
    ('rgbvolumes_json', dict(type=str, help="Output rgb volume file"))
  )
  def main(self,input_nii,origin_override,index2rgb_json,rgbcenters_json,rgbvolumes_json):
    nii = nibabel.load(input_nii)
    hdr = nii.get_header()
    q = hdr.get_best_affine();
    if origin_override:
      q[0:3,3] = -q[0:3,0:3].dot(origin_override)
    voxVol = numpy.linalg.det(q)
    img = nii.get_data().squeeze();

    labels = numpy.unique(img)
    rgbcenters = FancyDict()
    rgbvolumes = FancyDict()
    unmapped = []
    for b in labels:
      rgbcenters[b],rgbvolumes[b] = CenterFromImg().setInput(img=img,q=q,voxVol=voxVol,b=b).requestOutput('xyz','vol')
    
    rgbcenters.resolve()
    rgbvolumes.resolve()

    if index2rgb_json:
      with open(index2rgb_json,'r') as fp:
        index2rgb = json.load(fp)
        rgbcenters = {index2rgb[b]:ctr for b,ctr in rgbcenters.items()}
        rgbvolumes = {index2rgb[b]:vol for b,vol in rgbvolumes.items()}
    
    with open(rgbcenters_json,'w') as fp:
      fp.write(repr(FancyLog.jsonifyValue(rgbcenters)))
    with open(rgbvolumes_json,'w') as fp:
      fp.write(repr(FancyLog.jsonifyValue(rgbvolumes)))

    return FancyDict(
      rgbcenters_json=rgbcenters_json,
      rgbvolumes_json=rgbvolumes_json,
      unmapped=unmapped
    )

if __name__ == '__main__':
  NiftiToCenters.fromCommandLine().run()
