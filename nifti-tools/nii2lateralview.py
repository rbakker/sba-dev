import argparse, sys, numpy, json
import os.path as op
import re
import numpy
import jsonrpc2
import vtk
import nibabel
from nibabel.affines import apply_affine
from PIL import Image
import niitools as nit

report = jsonrpc2.report_class()

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description="""
      Downsamples a nifti volume by an integer factor in all three dimensions.
    """,
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-i','--inp', type=str, help="Input Nifti file", required=True)
  parser.add_argument('-o','--out', type=str, help="Output PNG screenshot", required=False)
  parser.add_argument('-x3d','--x3d', type=str, help="Output X3D scene", required=False)
  parser.add_argument('-stl','--stl', type=str, help="Output STL file", required=False)
  parser.add_argument('-bg','--bg-color', type=str, default="auto", help="Background color (default: auto)", required=False)
  parser.add_argument('-r','--render', action='store_true', help="Render the output in an OpenGL window", required=False)
  parser.add_argument('--reorient', type=str, help="Reorient volume by permuting and flipping axes. Example: '+j+i+k' to permute dimension i and j.", default=None)
  parser.add_argument('--replace', action='store_true', help="If set, existing lateral view will be replaced")
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

    return args
  except:
    report.fail(__file__)
        
def take_screenshot(filename,renderer):
    w2i = vtk.vtkRenderLargeImage()
    w2i.SetMagnification(1)
    w2i.SetInput(renderer)
    w2i.Update()

    writer = vtk.vtkPNGWriter()
    writer.SetInputConnection(w2i.GetOutputPort())
    writer.SetFileName(filename)
    writer.Write()

def run(args):
  try:
    # inspired by /my/github/3dbar/lib/pymodules/python2.6/bar/rec/default_pipeline.xml
    
    if op.exists(args.out):
      if not args.replace: 
        print('Skipping creation of image "{}", it already exists.'.format(args.out))
        result = {
          'Status': 'Skipped'
        }
        report.success(result)        
        return

    nii = nibabel.load(args.inp)
    
    # Nifti data is supposed to be in RAS orientation.
    # For Nifti files that violate the standard, the reorient string can be used to correct the orientation.
    if isinstance(args.reorient,str):
      nii = nit.reorient(nii,args.reorient)
    
    nii = nibabel.as_closest_canonical(nii)
    # numpy.array() will copy image to 'clean' data buffer
    img = numpy.squeeze(nii.get_data())

    if (args.bg_color == "auto"):
      bgColor = img[0,0,0]
    else:
      bgColor = int(args.bg_color)
    
    mask = nit.imageMask(img,[bgColor])
    img[mask] = 8
    if img.dtype != numpy.uint8:
      img = img.astype(numpy.uint8)
    
    #if numpy.prod(img.shape) > 512*512*512:
    hdr = nii.get_header()
    q = hdr.get_best_affine()
    scalef = [1+v/512 for v in img.shape]
    if any(numpy.array(scalef)>1):
      print('Downsampling by a factor {} to reduce memory load'.format(scalef))
      print 'Best affine before downsampling: {}'.format(q)
      (img,q) = nit.downsample3d(img,q.tolist(),scalef)
      print 'Best affine after downsampling: {}'.format(q)
    
    # image zero-padding
    bb = nit.get_boundingbox(img,2)
    w = 2
    padded = numpy.zeros((bb[3]+2*w,bb[4]+2*w,bb[5]+2*w),numpy.uint8,order='F');
    padded[w:-w,w:-w,w:-w] = img[bb[0]:bb[0]+bb[3],bb[1]:bb[1]+bb[4],bb[2]:bb[2]+bb[5]]
    img = padded
    dims = img.shape
    spacing = q.diagonal()[0:3]
    print('Origin before {}'.format(q[0:3,3])) 
    # origin adjusted for zero-padding
    q[0:3,3] = apply_affine(q,[bb[0]-w,bb[1]-w,bb[2]-w])
    origin = q[0:3,3]
    print('Origin after {}'.format(origin)) 
    
    doRender = args.render

    # create a rendering window and renderer
    ren = vtk.vtkRenderer()
    if doRender:
      renWin = vtk.vtkRenderWindow()
      iren = vtk.vtkRenderWindowInteractor()
      iren.SetRenderWindow(renWin)
    else:
      renWin = vtk.vtkRenderWindow()
      renWin.SetOffScreenRendering(1)
      
    renWin.AddRenderer(ren)
    WIDTH=800
    HEIGHT=600
    renWin.SetSize(WIDTH,HEIGHT)
     
    # import data
    dataImporter = vtk.vtkImageImport()
    dataImporter.SetImportVoidPointer(img)
    dataImporter.SetDataScalarTypeToUnsignedChar()
    dataImporter.SetNumberOfScalarComponents(1)
    dataImporter.SetDataExtent(0, dims[0]-1, 0, dims[1]-1, 0, dims[2]-1)
    dataImporter.SetWholeExtent(0, dims[0]-1, 0, dims[1]-1, 0, dims[2]-1)
    # new since October 2015
    dataImporter.SetDataSpacing(spacing)
    dataImporter.SetDataOrigin(origin)
    print 'ORIGIN: {}'.format(dataImporter.GetDataOrigin())

    print 'Dimensions {}'.format(dims)

    # create iso surface
    iso = vtk.vtkMarchingCubes()
    iso.SetInputConnection(dataImporter.GetOutputPort())
    iso.ComputeNormalsOff()
    iso.SetValue(0,3)

    iso.Update()

    #DEBUG
    #from vtk.util.numpy_support import vtk_to_numpy
    #iso.Update()
    #output = iso.GetOutput()
    #A = vtk_to_numpy(output.GetPoints().GetData())
    #print 'iso Output {}'.format(A)
    
    tf = vtk.vtkTriangleFilter()
    tf.SetInput(iso.GetOutput())
    
    # apply smoothing
    smth = vtk.vtkSmoothPolyDataFilter()
    smth.SetRelaxationFactor(0.5)
    smth.SetInput(tf.GetOutput())

    # reduce triangles
    qc = vtk.vtkQuadricClustering()
    qc.SetNumberOfXDivisions(90)
    qc.SetNumberOfYDivisions(90)
    qc.SetNumberOfZDivisions(90)
    qc.SetInput(smth.GetOutput())

    # map data
    volumeMapper = vtk.vtkPolyDataMapper()
    volumeMapper.SetInput( qc.GetOutput() )
    volumeMapper.ScalarVisibilityOff()

    # actor
    act = vtk.vtkActor()
    act.SetMapper(volumeMapper)
     
    # assign actor to the renderer
    ren.AddActor(act)
    bgColor = [0,0,0]
    ren.SetBackground(bgColor)

    camera = ren.GetActiveCamera()
    camera.SetViewUp(0,0,1)
    camera.SetFocalPoint(dims[0]/2,dims[1]/2,dims[2]/2)
    camera.SetPosition(dims[0],dims[1]/2,dims[2]/2)
    camera.ParallelProjectionOn()
    camera.Yaw(180)
    ren.SetActiveCamera(camera)
    ren.ResetCamera()
    ren.ResetCameraClippingRange()
    ren.GetActiveCamera().Zoom(1.5)
    
    if args.out:
      take_screenshot(args.out,ren)
      im = Image.open(args.out)
      A = numpy.array(im)
      mask = nit.imageMask(A,[bgColor])
      bgColor = [238,221,170]
      A[numpy.invert(mask)] = bgColor
      nonzero = numpy.argwhere(mask)
      if nonzero.size>0:
        lefttop = [v if v>0 else 0 for v in (nonzero.min(0)-4)]
        rightbottom = [v if v<A.shape[i] else A.shape[i] for i,v in enumerate(nonzero.max(0)+4)]
        A = A[lefttop[0]:rightbottom[0]+1,lefttop[1]:rightbottom[1]+1]
      im = Image.fromarray(A)
      sz = numpy.array(im.size,'double')
      sz = numpy.ceil((128.0/sz[1])*sz)
      print 'sz {}'.format(sz)
      im.thumbnail(sz.astype(int),Image.ANTIALIAS)
      im = im.convert('P', palette=Image.ADAPTIVE, colors=256)
      palette = numpy.array(im.getpalette()).reshape(256,3)
      match = numpy.where(numpy.all(palette == bgColor,axis=1))
      im.save(args.out,transparency=match[0])
      print('Created image "{}"'.format(args.out))
    
    if args.x3d:
      vtkX3DExporter = vtk.vtkX3DExporter()
      vtkX3DExporter.SetInput(renWin)
      vtkX3DExporter.SetFileName(args.x3d)
      vtkX3DExporter.Write()
      
    if args.stl:
      stlWriter = vtk.vtkSTLWriter()
      stlWriter.SetInputConnection(qc.GetOutputPort())
      stlWriter.SetFileTypeToBinary()
      stlWriter.SetFileName(args.stl)
      stlWriter.Write();

    # enable user interface interactor
    if doRender:
      iren.Initialize()
      renWin.Render()
      pos = camera.GetPosition()
      ornt = camera.GetOrientation()

      print 'Camera position; orientation {};{}'.format(pos,ornt)
      iren.Start()

    result = {
      'Status': 'Done'
    }
    report.success(result)
  except:
    report.fail(__file__)
    
if __name__ == '__main__':
  args = parse_arguments()
  run(args)
