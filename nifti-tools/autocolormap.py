# Rembrandt Bakker, Dhruv Kohli, Piotr Majka, June 2014

import argparse, sys, os, numpy, subprocess
import niitools as nit

def argument_parser():
  """ Define the argument parser and return the parser object. """
  parser = argparse.ArgumentParser(
    description='description',
    formatter_class=argparse.RawTextHelpFormatter)
  parser.add_argument('-n','--numcolors', type=int, help="Number of colors", required=True)
  return parser

def parse_arguments(raw=None):
  """ Apply the argument parser. """
  args = argument_parser().parse_args(raw)
  return args

def run(args):
  try:
    cmap = nit.contrastmap(args.numcolors)
    print '{}'.format(cmap)
    
    """
    rgbBackground = eval('rgbBackground','[]');
    grayLevels = eval('grayLevels','3');
    colorsPerCycle = grayLevels*(size(A,1)-1);
    mfactor = ceil(numColors/colorsPerCycle);
    for g=1:grayLevels,
      g0 = g-1;
      m = getmap((1-g0/grayLevels)*A,mfactor);
      map(g:grayLevels:grayLevels*size(m,1)+g0,:) = m;
    end
    if (shuffle),
      x = [0:mfactor:size(map,1)-mfactor]';
      y = 1:mfactor;
      z = x(:,ones(size(y)))+y(ones(size(x)),:);
      map = map(z(:),:);
    end
    if ~isempty(rgbBackground),
      if numel(rgbBackground)<3, rgbBackground = [1 1 1]; end
      map(1,:) = rgbBackground;
    end
    map = uint16(floor(255.99999999*map(1:numColors,:)));
    if strcmp(eval('output',''''''),'json'),
      rgb = {};
      for i=1:size(map,1),
        rgb{i} = sprintf('%02X%02X%02X',map(i,1),map(i,2),map(i,3));
      end
      map = json_encode(rgb,'s');
    end

    %%%
    %
    %%
    %
    function map = getmap(A,mfactor)

    [mA,nA] = size(A);
    B = zeros(mfactor*mA,nA);
    B(1:mfactor:end,:) = A;
    b = [1:mfactor mfactor-1:-1:1]/mfactor;
    B = filter(b,1,B,[],1);
    map = B(mfactor+1:end,:);
    """
    
  except:
    print "Error in {}.".format(__file__)
    exc_type, exc_value, exc_traceback = sys.exc_info() 
    import traceback
    print '==>'
    print ''.join(traceback.format_exception(exc_type, exc_value, exc_traceback))
    print '<=='
    raise

if __name__ == '__main__':
  args = parse_arguments()
  run(args)
