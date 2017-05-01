import sys,os
os.chdir(sys.path[0])
sys.path.append('../fancypipe')
from fancypipe import *
import os.path as op
import json
import numpy,nibabel
import scipy.misc

def sliceToSvg(svgPaths,brainslice):
  svg = []
  for rgb,paths in brainslice.items():
    for pid in paths:
      svg.append('<path d="{}" fill="#{}" stroke-width="0px"/>'.format(svgPaths[pid],rgb));
  return ''.join(svg);

  
class PathsToNifti(FancyTask):
  inputs = odict(
    ('paths_json', dict(type=assertFile, help="Input SVG paths")),
    ('brainslices_json', dict(type=assertFile, help="Brain slices to rgb-values to paths map")),
    ('boundingbox_svg', dict(type=assertList, help="Overall axis limits of slice stack in svg coordinates")),
    ('boundingbox_mm', dict(type=assertList, help="Overall axis limits of slice stack in mm")),
    ('slicepos_json', dict(type=assertFile, help="Slice position per slice", default=None)),
    ('index2rgb_json', dict(type=assertFile, help="Label index to rgb-value map", default=None)),
    ('labels_nii', dict(type=assertOutputFile, help="Output label volume",default=None))
  )
  def main(self,paths_json,brainslices_json,boundingbox_svg,boundingbox_mm,slicepos_json,index2rgb_json,labels_nii):
    with open(paths_json,'r') as fp:
      paths = json.load(fp)
    with open(brainslices_json,'r') as fp:
      brainslices = json.load(fp)
    numSlices = len(brainslices)
    if slicepos_json:
      with open(slicepos_json,'r') as fp:
        slicepos = json.load(fp)
      sliceSpacing = numpy.median(numpy.diff(slicepos))
      sliceOffset = slicepos[numSlices/2]-(numSlices/2)*sliceSpacing
    else:
      slicepos = range(0,numSlices)
      sliceSpacing = 1
      sliceOffset = 0
    
    img = None
    width_svg = abs(boundingbox_svg[2])
    height_svg = abs(boundingbox_svg[3])
    pngHeight = 800
    pngWidth = pngHeight*float(width_svg)/height_svg
    for s,bs in enumerate(brainslices):
      svgStart = '<svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" preserveAspectRatio="none" style="shape-rendering:crispEdges; text-rendering:geometricPrecision; fill-rule:evenodd; overflow: hidden" width="{}px" height="{}px" viewBox="{} {} {} {}">'.format(pngWidth,pngHeight,str(boundingbox_svg[0]),str(boundingbox_svg[1]),str(width_svg),str(height_svg))
      svg = sliceToSvg(paths,bs)
      svgEnd = '</svg>'
      svgFile = self.tempfile('slice{:04}.svg'.format(s))
      with open(svgFile,'w') as fp:
        fp.write(svgStart+svg+svgEnd)
      pngFile = self.tempfile('slice{:04}.png'.format(s))
      FancyExec().setProg('convert').setInput(
        '+antialias',
        svgFile,
        pngFile
      ).getOutput()
      data = scipy.misc.imread(pngFile)
      if img is None:
        img = numpy.zeros([data.shape[1],len(brainslices),data.shape[0]],data.dtype)
      else:
        img[:,s,::-1] = data.T

    xSlope = boundingbox_mm[2]/pngWidth
    xOffset = boundingbox_mm[0]+0.5*xSlope # position at center of voxel
    ySlope = boundingbox_mm[3]/pngHeight
    yOffset = boundingbox_mm[1]+0.5*ySlope # position at center of voxel
    q = numpy.array(numpy.eye(4))
    q[0,0] = xSlope
    q[0,3] = xOffset
    q[1,1] = sliceSpacing
    q[1,3] = sliceOffset
    q[2,2] = ySlope
    q[2,3] = yOffset
    nii = nibabel.Nifti1Image(img,q)
    if not labels_nii: labels_nii = self.tempfile('labels.nii.gz')
    nibabel.save(nii,labels_nii)
    return FancyDict(
      numPaths = len(paths),
      labels_nii = labels_nii
    )

if __name__ == '__main__':
  PathsToNifti.fromCommandLine().run()
