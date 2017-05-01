import os,sys
sys.path.append(os.path.join(sys.path[0],'fancypipe'))
sys.path.append('/my/github/FancyPipe/fancypipe')
from fancypipe import *
import numpy,nibabel

class MultiLabelSmooth(FancyTask):
  inputs = odict(
    ('raw_nii',dict(type=assertFile, help='Raw input image file')),
    ('filter',dict(type=assertList, help='one-dimensional array of filter coefficients')),
    ('ignore',dict(type=assertList, help='label indices to ignore in winning label assignment', default=[]))
  )
  def main(self,raw_nii,filter,ignore):
    nii = nibabel.load(raw_nii)
    hdr = nii.get_header()
    img = numpy.squeeze(nii.get_data())

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
      newimg[:,j,:] = newslice.astype(tp)

    smoothed_nii = self.tempfile('smoothed.nii.gz');  
    nibabel.nifti1.save(nibabel.nifti1.Nifti1Image(newimg,hdr.get_best_affine()),smoothed_nii)
    return FancyDict(smoothed_nii=smoothed_nii)

if __name__ == '__main__':
  MultiLabelSmooth.fromCommandLine().run()
