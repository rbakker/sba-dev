# Rembrandt Bakker October 2015
import sys
sys.path.append('../fancypipe')
from fancypipe import *
import nibabel
import niitools as nit
import jsonrpc2

report = jsonrpc2.report_class()

class RgbCenters(FancyTask):
  title = 'Convert areas to SVG'
  description = None
  inputs = {}
  
  def init(self):
    index2acr = rgb2acr.values()
    with open('index2rgb.json','w') as fp:
      json.dump(index2rgb,fp)
    with open('index2acr.json','w') as fp:
      json.dump(index2acr,fp)
    rgb2index = { v:k for k,v in enumerate(index2rgb) } 
    print '{}'.format(rgb2index)
    img = numpy.zeros(shape=[595,128,842],dtype=numpy.uint8)
    for i in range(1,128):
      pngfile = './coronal_png/Sectionc{0:03d}.png'.format(i)
      png = Image.open(pngfile)
      print '{} {}'.format(pngfile,png)
      slc = PIL2array(png,rgb2index)
      print '{}'.format(slc.shape)
      img[:,i-1,:] = slc.T
      
    img = img[::-1,::-1,::-1]
    q = numpy.array([[10./109,0,0,0],[0,0.48,0,0],[0,0,10./109,0],[0,0,0,1]])
    orig_px = numpy.array([180, 0, 224])
    q[0:3,3] = -q[0:3,0:3].dot(orig_px)
    nii = nibabel.Nifti1Image(img,q)
    #nii = nibabel.as_closest_canonical(nii) # this also sets the origin to center voxel!
    nibabel.save(nii,'MERetal14.nii.gz')
    return FancyOutput(
      status = 'Done.'
    )
#endclass

if __name__ == '__main__':
  RgbCenters.fromCommandLine().run()
