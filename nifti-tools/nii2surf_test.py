import argparse, sys, numpy, json
import os.path as op
import re
import numpy
import jsonrpc2
import vtk
import nibabel
import images2gif
from PIL import Image
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
  parser.add_argument('-o','--out', type=str, help="Output PNG screenshot", required=False)
  parser.add_argument('-r','--render', action='store_true', help="Render the output in an OpenGL window", required=False)
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
    nii = nibabel.load(args.inp)
    nii = nibabel.as_closest_canonical(nii)
    img = numpy.squeeze(nii.get_data())

    bgColor = img[0,0,0]
    mask = (img != bgColor)
    #img = numpy.zeros(mask.shape,numpy.uint8)
    img[mask] = 16

    if numpy.prod(img.shape) > 256*256*256:
      print('Downsampling by a factor 2 to reduce memory load')
      hdr = nii.get_header()
      q = hdr.get_best_affine()
      (img,q) = nit.downsample(img,q,2)
    
    dims = img.shape

    doRender = args.render
     
    # create a rendering window and renderer
    ren = vtk.vtkRenderer()
    if doRender:
      renWin = vtk.vtkRenderWindow()
      iren = vtk.vtkRenderWindowInteractor()
      iren.SetRenderWindow(renWin)
    else:
      renWin = vtk.vtkXOpenGLRenderWindow()
    renWin.AddRenderer(ren)
    WIDTH=250
    HEIGHT=160
    renWin.SetSize(WIDTH,HEIGHT)
    
    # import data
    dataImporter = vtk.vtkImageImport()
    dataImporter.SetImportVoidPointer(img)
    dataImporter.SetDataScalarTypeToUnsignedChar()
    dataImporter.SetNumberOfScalarComponents(1)
    dataImporter.SetDataExtent(0, dims[0]-1, 0, dims[1]-1, 0, dims[2]-1)
    dataImporter.SetWholeExtent(0, dims[0]-1, 0, dims[1]-1, 0, dims[2]-1)

    print 'Dimensions {}'.format(dims)

    # create iso surface
    iso = vtk.vtkMarchingCubes()
    iso.SetInputConnection(dataImporter.GetOutputPort())
    iso.ComputeNormalsOff()
    iso.SetValue( 0, 5 )
    ## necessary?? See /my/github/3dbar/lib/pymodules/python2.6/bar/rec/default_pipeline.xml

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

    images = []
    for i in range(0,360,15):
      az = 3*numpy.sin(2*numpy.pi*i/360)
      el = 3*numpy.cos(2*numpy.pi*i/360)
      ren.GetActiveCamera().Azimuth(az)
      ren.GetActiveCamera().Elevation(el)
      renWin.Render()
      pngfile = "/tmp/test{}.png".format(i)
      take_screenshot(pngfile,ren)
      im = Image.open(pngfile)
      images.append(im)
      ren.GetActiveCamera().Elevation(-el)
      ren.GetActiveCamera().Azimuth(-az)

    images2gif.writeGif('/tmp/test.gif',images,0.2,dither=0)
    
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
