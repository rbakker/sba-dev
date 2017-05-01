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
    rgbCenters = {b:{'left':numpy.zeros([4]),'both':numpy.zeros([4]),'right':numpy.zeros([4])} for b in labels}
    for i in range(0,img.shape[0]):
      for j in range(0,img.shape[1]):
        for k in range(0,img.shape[2]):
        #for k in range(0,10):
          b = img[i,j,k]
          xyz1 = q.dot([i,j,k,1])
          x = xyz1[0]
          if x<0: rgbCenters[b]['left'] += xyz1
          elif x==0: rgbCenters[b]['both'] += xyz1
          else: rgbCenters[b]['right'] += xyz1
    
    rgbVolumes = {b:{'left':0,'both':0,'right':0} for b in labels}
    for b in labels:
      if (b==10): FANCYDEBUG(rgbCenters[b]['both'])
      rgbCenters[b]['both'] += rgbCenters[b]['left']
      if (b==10): FANCYDEBUG(rgbCenters[b]['both'])
      rgbCenters[b]['both'] += rgbCenters[b]['right']
      if (b==10): FANCYDEBUG(rgbCenters[b]['both'])
      for side in ['left','both','right']:
        voxCount = rgbCenters[b][side][3]
        rgbVolumes[b][side] = voxCount*voxVol
        rgbCenters[b][side] = rgbCenters[b][side][0:3]/(voxCount if voxCount>0 else 1)

    if index2rgb_json:
      with open(index2rgb_json,'right') as fp:
        index2rgb = json.load(fp)
      rgbCenters = {index2rgb[b]:ctr for b,ctr in rgbCenters.items()}
      rgbVolumes = {index2rgb[b]:vol for b,vol in rgbVolumes.items()}
    
    with open(rgbcenters_json,'w') as fp:
      fp.write(repr(FancyLog.jsonifyValue(rgbCenters)))
    with open(rgbvolumes_json,'w') as fp:
      fp.write(repr(FancyLog.jsonifyValue(rgbVolumes)))

    return FancyDict(
      rgbcenters_json=rgbcenters_json,
      rgbvolumes_json=rgbvolumes_json
    )

if __name__ == '__main__':
  NiftiToCenters.fromCommandLine().run()
