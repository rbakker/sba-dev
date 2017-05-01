# FancyPipe
# ---------
# FancyPipe is a pure python package that takes care of those
# aspects of complex data processing pipelines that would otherwise 
# distract you from your main job: the design of the processing steps.
#
# FancyPipe takes care of
# * command line parsing
# * logging to dynamic html-file and console
# * parallel execution
# * temporary file management
# * error handling
# * reading parameters from a configuration file
# * running external commands
#
# To use FancyPipe effectively, you need to wrap your processing steps 
# as FancyTask classes, and define input-output connections between tasks.
#
# FancyPipe works in both Python 2.7 and Python 3
#
# Links used while developing this code:
# https://pythonhosted.org/joblib/parallel.html
# http://eli.thegreenplace.net/2012/01/24/distributed-computing-in-python-with-multiprocessing
# http://bugs.python.org/issue7503
# https://www.artima.com/weblogs/viewpost.jsp?thread=240845
#

from __future__ import print_function
from __future__ import unicode_literals

# These functions and classes get imported after "from fancypipe import *"
__all__ = [
  'assertPassword','assertBool','assertFile','assertOutputFile','assertDir','assertList','assertMultiSelect','assertDict','assertExec','assertToken',
  'assertMatch','assertInstance','assertType','assertSelect',
  'odict','fancyPrint','fancyLog',
  'FANCYDEBUG',
  'FancyOutputFile','FancyTempFile','FancyPassword','FancyLog','FancyRequest','FancyValue','FancyArgs','FancyList','FancyDict',
  'FancyTask','FancyTaskInstance','FancyExec','FancyTaskManager'
]

import os,sys
import os.path as op
import argparse, subprocess
import tempfile, datetime, json
import codecs
from collections import OrderedDict
import inspect
import re,uuid
import threading, multiprocessing, multiprocessing.queues, multiprocessing.managers
try:
  import queue
except ImportError:
  import Queue as queue
try:
  import cPickle as pickle
except ImportError:
  import pickle
import traceback
import string,StringIO, random
import gc # garbage collection

# Global task manager and worker thread/process
global_taskManager = None

# Constants to indicate what type of result a task returns.
Result_Success = 0
Result_Failed = 1
Result_Print = 2
Result_Log = 3

# Constants to indicate log targets.
LogTo_Console = 1
LogTo_File = 2
LogTo_Overwrite = 4

## Constants to indicate the run-status of a task.
Task_Reset = 0
Task_ResolvingInput = 1
Task_Submitted = 2
Task_ResolvingOutput = 3
Task_Completed = 4
Task_Failed = 5

# Print a message from a possibly remote worker.
def fancyPrint(s):
  if global_taskManager: global_taskManager.print(s)
  else: print(s)

# Create log file entry
def fancyLog(data,name=None,tp='message'):
  global_taskManager.log(None,data,name,tp)

# Ordered dictionary class for setting inputs.
class odict(OrderedDict):
  def __init__(self, *keyvals):
    try:
      OrderedDict.__init__(self,*keyvals)
    except:
      OrderedDict.__init__(self)
      if keyvals and keyvals[0]:    
        if isinstance(keyvals[0],(tuple,list)):
          OrderedDict.__init__(self,keyvals)
        else:
          for i in range(0,len(keyvals),2):
            self[keyvals[i]] = keyvals[i+1]

  def extend(self,keyvals,*keys):
    for k in keys:
      self[k] = keyvals[k]
#endclass

# Password class that hides the password in logs
class FancyPassword:
  def __init__(self,pwd):
    self.value = pwd
  def __repr__(self):
    return '***'

# Assert functions are used to parse and validate command line inputs
def assertPassword(s):
  return FancyPassword(s)

def assertBool(s):
  if (s is True or s.lower() == 'true' or s == '1'): return True
  elif (s is False or s.lower() == 'false' or s == '0'): ans = False
  else: raise AssertionError('Option "{}" does not represent a boolean value.'.format(s))

def assertFile(s):
  """ Assert that the input is an existing file. """
  if not op.isfile(s): raise AssertionError('String "{}" is not an existing file.'.format(s))
  return s

def assertOutputFile(s):
  """ Assert that the input is a valid filename, to be used as an output file. """
  if not os.access(op.dirname(s), os.W_OK): raise AssertionError('String "{}" does not represent a valid output file.'.format(s))
  return FancyOutputFile(s)

def assertDir(s):
  """ Assert that the input is an existing directory. """
  if not op.isdir(s): raise AssertionError('String "{}" is not an existing directory.'.format(s))
  return s

def assertList(s):
  """ Assert that the input is a list or tuple. """
  if isinstance(s,str): 
    try:
      s = json.loads(s)
    except:
      raise AssertionError('String "{}" cannot be json-decoded.'.format(s))
  if not isinstance(s,(list,tuple)): raise AssertionError('Variable "{}" is not a list.'.format(s))
  return s

def assertMultiSelect(s):
  """ Assert that the input selects one or multiple pages, like 1,2,4-8 """
  if isinstance(s,str):
    parts = s.lstrip('[').rstrip(']').split(',')
    s = []
    for p in parts:
      p = [int(v) for v in p.split('-')]
      if len(p)>1: p = range(p[0],p[1]+1)
      s.extend(p)
    s = list(set(s))
  return assertList(s)
  
def assertDict(s):
  """ Assert that the input is a dictionary. """
  if isinstance(s,str): 
    try:
      s = json.loads(s)
    except:
      raise AssertionError('String "{}" cannot be json-decoded.'.format(s))
  if not isinstance(s,dict): raise AssertionError('Variable "{}" is not a dictionary.'.format(s))
  return s

def assertExec(s):
  """ Assert that the input can be executed and return the full path to the executable. """
  import os
  def is_exe(fpath):
    return os.path.isfile(fpath) and os.access(fpath, os.X_OK)

  fpath,fname = op.split(s)
  if fpath:
    if is_exe(s):
      return s
  else:
    for path in os.environ["PATH"].split(os.pathsep):
      path = path.strip('"')
      exe_file = op.join(path,s)
      if is_exe(exe_file):
        return exe_file
  raise AssertionError('Cannot find executable command "{}".'.format(s))

def assertToken(s):
  return s.strip()

# call as assertMatch(...)
def assertMatch(regexp,fromStart=True,decompose=True):
  def f(s):
    if fromStart: matches = re.match(regexp,s)
    else: matches = re.search(regexp,s)
    if matches: 
      if decompose: return matches.groups()
      else: return s
    raise AssertionError('String "{}" has no match for regular expression "{}".'.format(s,regexp))
  return f

# call as assertInstance(...)
def assertInstance(cls):
  def f(v):
    if isinstance(v,cls): return v
    raise AssertionError('Variable "{}" is not an instance of "{}".'.format(v,cls))
  return f

# call as assertType(...)
def assertType(tp,allow=set()):
  def f(v):
    try: 
      return tp(v)
    except ValueError:
      if v in allow:
        return allow[v]
      else:
        raise AssertionError('Value "{}" cannot be converted to type "{}".'.format(v,tp))
  return f

# call as assertSelect(...)
def assertSelect(*choices):
  def f(s):
    if s in choices:
      return s
    else:
      raise AssertionError('Value "{}" isn\'t one of: {}.'.format(s,choices))
  return f

def assertJobServer(v):
  if v:
    addr = v.split(':')
    port = int(addr[1]) if len(addr)>1 else 51423
    return (addr[0],port)
  else:
    return v
    
## Use for debugging: prints argument values and their context in contrasting green
def FANCYDEBUG(*args,**kwargs):
  traceback = kwargs['traceback'] if 'traceback' in kwargs else 3
  frame = inspect.currentframe()
  outerframes = inspect.getouterframes(frame)
  msg = '___\n'
  try:
    msg += ''.join(['{}. {}\n'.format(i,v[4][0].strip()) for i,v in enumerate(outerframes[1:min(len(outerframes),traceback+1)])])
  except:
    pass
  msg += '\n'.join([repr(FancyLog.jsonifyValue(v,summarize=True)) for v in args])+'\n'
  FancyReport.warning(msg)


## Use this class to indicate that the string represents an outputfile.
## In recycling mode, fancypipe will check whether the file can be recycled.
class FancyOutputFile(str):
  pass
#endclass


## Use this class to indicate that the string represents a temporary file.
## It will be removed when no longer needed.
class FancyTempFile(FancyOutputFile):
  pass
#endclass


class ArgParse:
  # Parse external inputs, from commandline or configfile.
  @staticmethod
  def _parseInputs(inputs,raw,cfg,presets={}):
    if inputs is None: return {}
    kwargs = {}
    errors = []
    for key,inp in inputs.items():
      if key in presets:
        kwargs[key] = presets[key]
      elif key in cfg:
        # typecast
        tp = inp['type'] if 'type' in inp else str
        kwargs[key] = tp(cfg[key])
      elif 'default' in inp:
        if hasattr(inp['default'],'__call__'):
          kwargs[key] = inp['default'](kwargs)
        else:
          kwargs[key] = inp['default']
    if raw is not None:
      for key,inp in inputs.items():
        if key in raw:
          # typecast
          tp = inp['type'] if 'type' in inp else str
          kwargs[key] = tp(raw.pop(key))
        elif key not in kwargs:
          errors.append('Missing input "{}". Searched commandline arguments, configuration file and default.'.format(key))
    if errors: raise ValueError('\n'.join(errors))      
    return kwargs

  @staticmethod
  def parseInputs(cls,cmdArgs,fancyConfig=None,presets={}):
    configArgs = fancyConfig.classDefaults(cls.__name__) if fancyConfig else {}
    return ArgParse._parseInputs(cls.inputs,cmdArgs,configArgs,presets)
#endclass


## Used for output and error reporting, with an option to use json-rpc 2
class FancyReport:
  inputs = odict(
    ('jsonrpc2',dict( action='store_true', default=False,
      help='Capture output and return result in jsonrpc2 format.')
    )
  )
    
  rpc_id = None
  
  def __init__(self,jsonrpc2=False):
    if jsonrpc2:
      # capture output from print statements
      self.stdout0 = sys.stdout
      self.stdout1 = StringIO.StringIO()
      sys.stdout = self.stdout1 # redirect
      self.stderr0 = sys.stderr
      self.stderr1 = StringIO.StringIO()
      sys.stderr = self.stderr1 # redirect
      rand16 = ''.join(random.SystemRandom().choice(string.ascii_letters + string.digits) for _ in range(16))
      self.rpc_id = rand16
      
  def uncapture(self):
    sys.stdout = self.stdout0
    sys.stderr = self.stderr0
    return self.stdout1.getvalue()

  def __del__(self):
    if self.rpc_id:
      self.uncapture()
      self.rpc_id = None

  @staticmethod
  def warning(s): sys.stderr.write('\033[1;32m{}\033[0m\n'.format(s))
  
  @staticmethod
  def error(s): sys.stderr.write('\033[1;31m{}\033[0m\n'.format(s))

  @staticmethod
  def traceback():
    exc_type, exc_value, exc_traceback = sys.exc_info() 
    return traceback.format_exception(exc_type, exc_value, exc_traceback)

  @staticmethod
  def errorTrace(e=None):
    args = {}
    if (e):
      for k in dir(e):
        if k[0] != '_':
          args[k] = getattr(e,k)
    exc_type, exc_value, exc_traceback = sys.exc_info()
    tb = traceback.format_exception(exc_type, exc_value, exc_traceback)
    msg = tb.pop()
    return dict(args=args,exception=exc_type.__name__,message=msg,traceback=tb)
    
  def success(self,result):
    if self.rpc_id:
      self.uncapture()
      result = {
        'jsonrpc':'2.0',
        'id':self.rpc_id,
        'result': FancyLog.jsonifyValue(result),
        'stdout': self.stdout1.getvalue(),
        'stderr': self.stderr1.getvalue()
      }
      indent = 2
    else:
      result = FancyLog.jsonifyValue(result,summarize=True)
      indent = 2
    print(json.dumps(result,indent=indent))

  def fail(self,msg,code=1):
    traceback = self.traceback()
    msg = traceback.pop()
    if self.rpc_id:
      self.uncapture()
      result = {
        'jsonrpc':'2.0',
        'id': self.rpc_id,
        'error':{
          'code': code,
          'message': msg,
          'data': {
            'stdout': self.stdout1.getvalue(),
            'stderr': self.stderr1.getvalue(),
            'traceback': traceback
          }
        }
      }
      print(json.dumps(result,indent=2))
      sys.exit(0)
    else:
      result = '{}\n{}\n\n'.format(msg,''.join(FancyReport.traceback()))
      FancyReport.error(result)
      sys.exit(code)
#endclass


## Used for logging to console and html-file.
class FancyLog:
  def __init__(self, logDir=None,logLevel=3,logTo=(LogTo_Console|LogTo_File|LogTo_Overwrite)):
    self.logDir = logDir
    self.logFile = None
    if logDir:
      if not op.isdir(logDir):
        fancyPrint('Creating new log directory "{}".'.format(logDir))
        os.makedirs(logDir)
      logFilesDir = op.join(logDir,'fancylog_files')
      if not op.isdir(logFilesDir):
        fancyPrint('Creating new log_files directory "{}".'.format(logFilesDir))
        os.mkdir(logFilesDir)
      self.logDir = logDir
      self.logFile = op.join(logDir,'fancylog.js')
      if not op.isfile(self.logFile):
        self.reset(logTo & LogTo_Overwrite)
    self.logTo = logTo if self.logFile else logTo & ~LogTo_File
    self.logLevel = logLevel
  
  def reset(self,overwrite=False):
    if op.isfile(self.logFile) and not overwrite:
      raise Exception('Logfile "{}" already exists, use RESET to overwrite.'.format(self.logFile))
    with codecs.open(self.logFile,'w',encoding='utf-8') as log:
      log.write('var LOG = [];\n\n')
    htmlsrc = op.join(op.dirname(__file__),'fancylog.html')
    htmltgt = op.join(self.logDir,'fancylog.html')
    if not op.isfile(htmltgt):
      import shutil
      shutil.copyfile(htmlsrc,htmltgt)

  @staticmethod
  def jsonifyValue(v,summarize=False):
    if not summarize and hasattr(v,'tolist'): return v.tolist()
    if isinstance(v,(tuple, list)): return FancyLog.jsonifyList(v,summarize)
    if isinstance(v,(dict)): return FancyLog.jsonifyDict(v,summarize)
    if isinstance(v,(int,bool,float)) or v is None: return v
    if hasattr(v,'jsonify'): return v.jsonify(summarize)
    return v if isinstance(v,(str,unicode)) else str(v)

  @staticmethod
  def jsonifyList(args,summarize=False):
    n = len(args)
    if summarize and n>32:
      args = [ args[i] for i in [0,1,2,3,-2,-1,0] ]
      args[3] = '... array({}) ...'.format(n-6)
    return [FancyLog.jsonifyValue(v,summarize) for v in args]

  @staticmethod
  def jsonifyDict(kwargs,summarize=False):
    items = kwargs.items()
    if summarize and len(items)>32:
      items = [ items[i] for i in [0,1,2,3,-2,-1,0] ]
      items[3] = ('(...)','(...)')
    return { str(k):FancyLog.jsonifyValue(v,summarize) for k,v in items }
    
  def appendLog(self,s):
    with codecs.open(self.logFile,'a',encoding='utf-8') as log:
      log.write(s)
    
  # Add a task-entry to the logFile
  def logTask(self,task,name=None):
    logLevel = task.logLevel if task.logLevel is not None else self.logLevel
    if not logLevel: return
    toConsole = self.logTo & LogTo_Console
    toFile = (self.logTo & LogTo_File) and self.logFile
    cmd = name if name else task.getName()
    title = task.title
    lines = ['___\nLog({}) {}'.format(task.taskId,cmd+': '+title if title else cmd)]
    starttime = datetime.datetime.now()
    params = {
      'id': task.taskId,
      'cmd': cmd,
      'timeStamp': starttime.isoformat()
    }
    if logLevel>1:
      # prepare args for json-dumping
      if task.myInput.args:
        params['args'] = self.jsonifyList(task.myInput.args,summarize=True)
      if task.myInput.kwargs:
        params['kwargs'] = self.jsonifyDict(task.myInput.kwargs,summarize=True)
      if title:
        params['title'] = title
      commandLine = task.getCommandLine()
      if commandLine:
        lines.append('Command line: {}'.format(commandLine))
        params['commandLine'] = commandLine
    if toConsole:
      # write to stdout
      lines.append('Arguments: {}'.format(json.dumps(params,indent=2)))
      print('\n'.join(lines))
    if toFile:
      # write to logFile
      self.appendLog('LOG.push('+json.dumps(params)+')\n')
    
  def logResult(self,task,data,name=None,tp=None):
    logLevel = task.logLevel if task.logLevel is not None else self.logLevel
    if not logLevel: return
    toConsole = self.logTo & LogTo_Console
    toFile = (self.logTo & LogTo_File) and self.logFile
    # write to logFile
    if toFile:
      logId = task.taskId
      params = {
        'attachTo':logId,
        'name':name,
        'type':tp
      }
      ##summary = json.dumps(self.jsonifyValue(data,summarize=True),indent=2)
      summary = self.jsonifyValue(data,summarize=True)
      params['result'] = summary
      if tp=='longtext':
        full = json.dumps(self.jsonifyValue(data))
        if summary != full:
          logFilesdir = op.join(self.logDir,'fancylog_files')
          outfile = op.join(logFilesdir,'stdout_{}.txt'.format(logId))
          with codecs.open(outfile,"w",encoding='utf-8') as fp:
            fp.write('\n'.join(data))      
          params['result'] = {
            'summary': summary,
            'name':outfile,
            'url':'file:///{}'.format(outfile)
          }
      params_json = json.dumps(params,indent=2);
      self.appendLog('LOG.push('+params_json+')\n')
    if toConsole:
      if tp == 'error':
        if data['traceback']:
          data['traceback'] = ''.join(data['traceback'])
        FancyReport.error(data)
      else:
        print('Result "{}" for task "{}" is ready.'.format(name,task))
#endclass


## Used to keep track of temporary (result) files.
class FancyClean:
  def __init__(self):
    self.files = set()
    self.dirs = set()

  def update(self,files,dirs=None):
    self.files.update(files)
    if dirs: self.dirs.update(dirs)
  
  def addNewFile(self,f,autoremove):
    if autoremove and not op.isfile(f): self.files.add(f)
        
  def addCreateDir(self,d,autoremove):
    create = []
    while not op.exists(d):
      create.append(d)
      head,tail = op.split(d)
      d = head
    for d in reversed(create):
      try:
        os.mkdir(d)
        if autoremove: self.dirs.add(d)
      except OSError: # thrown if two processes try to create dir at the same time
        pass

  def cleanup(self,exclude,taskKey):
    if (not self.files and not self.dirs): return (set(),set())
    excludedFiles = set()
    excludedDirs = set()
    E = set()
    for path in exclude:
      E.add(op.realpath(path))
    for f in self.files:
      F = op.realpath(f)
      if F not in E:
        try:
          os.remove(f)
          fancyPrint('{} deleted file "{}".'.format(taskKey,f))
        except:
          FancyReport.warning('{} tried to delete file "{}", but failed.'.format(taskKey,f))
      else:
        excludedFiles.add(f)
    for d in reversed(sorted(self.dirs)):
      D = op.realpath(d)
      try:
        os.rmdir(d)
        fancyPrint('{} removed folder "{}".'.format(taskKey,d))
      except:
        excludedDirs.add(d)
    return (excludedFiles,excludedDirs)
#endclass


## Used to load and keep track of configuration parameters.
class FancyConfig:
  inputs = odict(
    ('configFile', dict(
      type=assertFile, default=None,
      help='Configuration file (XML or JSON), to read default parameters from. Default: None.'
    ))
  )

  def __init__(self,config={}):
    self.config = config

  @staticmethod
  def etree_to_odict(t):
    if len(t):
      od = odict()
      for ch in t:
        od[ch.tag] = FancyConfig.etree_to_odict(ch)
      return od
    else:
      return t.text

  @classmethod
  def fromFile(cls,configFile):
    if configFile is None:
      return cls()
    name,ext = op.splitext(configFile)
    if ext.lower() == '.xml':
      from lxml import etree
      tree = etree.parse(configFile)
      root = tree.getroot()
      config = FancyConfig.etree_to_odict(root)
      if root.tag != 'config': config = odict((root.tag,config))
      return cls(config)
    elif ext.lower() == '.json':
      return cls(json.load(configFile, object_pairs_hook=odict))
    else:
      raise RuntimeError('Configuration file "{}" has an unrecognized format.'.format(configFile))

  @classmethod
  def fromParent(cls,parentTask):
    try:
      config = parentTask.fancyConfig.config.copy()
      parentClass = parentTask.__class__.__name__
      if parentClass in config:
        # overwrite defaults with className-specific defaults
        for k,v in config[parentClass].items(): config[k] = v;
        config.pop(parentClass)
      return cls(config)
    except:
      return cls()
    
  def classDefaults(self,taskClass):
    return self.config[taskClass] if taskClass in self.config else {}
#endclass


## Indicates an outgoing link of output <outKey> of task <task>.
class FancyRequest:
  def __init__(self,task,outKey):
    self.task = task # task that will supply the value
    self.outKey = outKey # key of the above task's output (or ALL for complete output)
      
  def __getitem__(self,key):
    return FancyRequestItem().setInput(self,key).requestOutput()
    
  def __repr__(self):
    return 'FancyRequest<{}[{}]>'.format(self.task,self.outKey)
#endclass
  
  
## Class that represents ALL arguments
class ALL:
  def __repr__(self):
    return 'ALL'
#endclass


## Base class for task input/output, with a single argument
class FancyValue:
  def __init__(self,value):
    self.value = value
  
  def getValue(self): 
    return self.value
  
  def jsonify(self,summarize=False):
    return FancyLog.jsonifyValue(self.getValue(),summarize)
    
  def __repr__(self):
    return repr(self.jsonify())

  def __str__(self):
    return str(self.jsonify(summarize=True))

  @staticmethod
  def _ready(v):
    return False if isinstance(v,FancyRequest) else v.ready() if isinstance(v,FancyValue) else True

  def ready(self):
    return self._ready(self.value)

  @staticmethod
  def _tempfiles(ans,v):
    if isinstance(v,FancyTempFile): ans.add(str(v))
    elif isinstance(v,FancyValue): ans.update( v.tempfiles() )

  def tempfiles(self):
    ans = set()
    self._tempfiles(ans,self.value)
    return ans

  def __getitem__(self,key):
    if key is ALL: return self.value
    else: return self.value[key]

  def __setitem__(self,key,val):
    if key is ALL: self.value = val
    else: self.value[key] = val

  def resolve(self):
    if not global_taskManager.runningTask:
      raise RuntimeError('FancyValue.resolve() must be called from within the main() method of a FancyTask.')
    pendingTasks = global_taskManager.runningTask.initRequests(self)
    if pendingTasks: global_taskManager.resolve( pendingTasks )
#endclass


## FancyValue with only positional arguments.
class FancyList(list,FancyValue):
  def __init__(self,*args):
    if len(args)>1:
      raise TypeError('FancyList takes only one argument (a list or tuple).')
    args = args[0] if len(args)>0 else []
    if not isinstance(args,(list,tuple)):
      raise TypeError('FancyList argument must be a list or tuple.')
    list.__init__(self,args)
    self.args = self

  def getValue(self): 
    return self
  
  def ready(self):
    for i,v in enumerate(self):
      if not self._ready(v): return False
    return True

  def tempfiles(self):
    ans = set()
    for i,v in enumerate(self):
      self._tempfiles(ans,v)
    return ans
#endclass


## FancyValue with only keyword arguments.
class FancyDict(dict,FancyValue):
  def __init__(self,*args,**kwargs):
    if not kwargs:
      if len(args)>1:
        raise TypeError('FancyDict takes only one positional argument (a dict).')
      kwargs = args[0] if len(args)>0 else {}
    dict.__init__(self,kwargs)
    self.kwargs = self

  def getValue(self):
    return self
  
  def ready(self):
    for k,v in self.items():
      if not self._ready(v): return False
    return True

  def tempfiles(self):
    ans = set()
    for k,v in self.items():
      self._tempfiles(ans,v)
    return ans
#endclass


## FancyValue with positional and keyword arguments
class FancyArgs(FancyValue):
  def __init__(self,*args,**kwargs):
    self.args = FancyList(args)
    self.kwargs = FancyDict(kwargs)

  def getValue(self): 
    return dict(args=self.args,kwargs=self.kwargs)
  
  def ready(self):
    return self.args.ready() and self.kwargs.ready()

  def tempfiles(self):
    return self.args.tempfiles() | self.kwargs.tempfiles()

  def __getitem__(self,key):
    return self.args[key] if isinstance(key,int) else self.kwargs[key]

  def __setitem__(self,key,val):
    if isinstance(key,(int)): self.args[key] = val
    else: self.kwargs[key] = val
    
  def items(self): 
    return self.kwargs.items()
#endclass


## Fancy task class, supports parallel execution, logging, tempdir, config file.
class FancyTask:
  name = None
  title = None
  description = None
  inputs = None 

  _tempdir = None
  _numChildren = 0
  taskId = '0'
  parentKey = None
  runParallel = False
  fancyClean = None
  fancyConfig = None
  requests = {}
  sourceLinks = {}
  recycle = False
  logLevel = None
  
  def __init__(self,logLevel=None,taskManager=None, fancyConfig=None,taskId=None,autoRemove=True):
    if taskManager:
      global global_taskManager
      global_taskManager = taskManager
      global_taskManager.runningTask = self

    #self.myInput = FancyArgs()
    self.reset()

    if not global_taskManager:
      raise RuntimeError("Initialize global_taskManager before instantiating a task.") 
    if not global_taskManager.runningTask:
      global_taskManager.runningTask = self
    parent = global_taskManager.runningTask
    
    if parent is not self:
      self._tempdir = op.join(parent._tempdir,self.tempsubdir(parent._numChildren))
      if taskId is None: taskId = parent.newChildId()
      if logLevel is None: logLevel = parent.logLevel # inherit
      if fancyConfig is None: fancyConfig = FancyConfig.fromParent(parent)
      if hasattr(parent,'autoRemove'): autoRemove = parent.autoRemove
      self.parentKey = str(parent)
    else:
      self._tempdir = global_taskManager.workDir
      
    if taskId is not None:
      self.taskId = taskId
    if logLevel is not None:
      self.logLevel = logLevel
    if fancyConfig is not None:
      self.fancyConfig = fancyConfig
    if autoRemove is not None:
      self.autoRemove = autoRemove
      self.recycle = not autoRemove
    

  @classmethod
  def fromCommandLine(cls,**presets):
    # Parse command line
    parser = FancyTaskManager._getParser(cls)
    (cmdArgs,remaining) = parser.parse_known_args()
    cmdArgs = vars(cmdArgs)
    
    # Get jsonrpc2 as early as possible, to capture errors
    parsedArgs = ArgParse.parseInputs(FancyReport,cmdArgs,presets=presets)
    jsonrpc2 = parsedArgs['jsonrpc2']
    report = FancyReport(jsonrpc2)

    try:
      if remaining: report.warning('Unused arguments passed to class {}: {}'.format(cls,','.join(remaining)))

      # Prepare fancyConfig
      parsedArgs = ArgParse.parseInputs(FancyConfig,cmdArgs,presets=presets)
      configFile = parsedArgs['configFile']
      fancyConfig = FancyConfig.fromFile(configFile)
  
      # Parse inputs
      parsedArgs = ArgParse.parseInputs(FancyTaskManager,cmdArgs,fancyConfig=fancyConfig,presets=presets)
      
      # TaskManager setup.
      workDir = parsedArgs['workDir'] 
      if workDir is None: workDir = op.join(tempfile.gettempdir(),cls.tempsubdir(0))
      logDir = parsedArgs['logDir']
      logLevel = parsedArgs['logLevel']
      logMode = parsedArgs['logMode']
      jobServer = parsedArgs['jobServer']
      jobAuth = parsedArgs['jobAuth']
      try: numWorkers = int(parsedArgs['numWorkers'])
      except: numWorkers = multiprocessing.cpu_count()
      workerType = parsedArgs['workerType'][0]
      
      global global_taskManager
      global_taskManager = FancyTaskManager(report,workDir,logDir,logLevel,logMode,jobServer,jobAuth,numWorkers,workerType)

      # Task setup.
      autoRemove = parsedArgs['autoRemove']

      # Parse task inputs
      parsedArgs = ArgParse.parseInputs(cls,cmdArgs,fancyConfig=fancyConfig,presets=presets)

      # Instantiate.
      self = cls(**{
        'fancyConfig': fancyConfig,
        'autoRemove': autoRemove
      })(**parsedArgs)
    except:
      traceback = report.traceback()
      report.fail(msg='Fatal error in class {}.'.format(cls),)
        
    return self    


  def __getitem__(self,keys):
    return self.requestOutput(*keys)


  # Reset output and runStatus of a task; input is preserved.
  def reset(self):
    self.runStatus = Task_Reset
    self.myInput = FancyArgs()
    self.myOutput = None
    if self.requests: self.requests = {} # for each sourceKey, a list of target task ids to send the result to
    if self.sourceLinks: self.sourceLinks = {} # for each (sourceTask,sourceKey) a list of (targetArgs,targetKey) pairs that this task receives data from


  def newChildId(self):
    self._numChildren += 1
    return self.taskId+'.'+str(self._numChildren)


  def getName(self):
    return self.name if self.name else self.__class__.__name__

    
  def __repr__(self):
    return "%s[%s]" % (self.getName(), self.taskId)


  @classmethod
  def tempsubdir(cls,taskId):
    return '{}_{}'.format(taskId,cls.__name__)

  # tempdir(subdir) returns tempdir/subdir, and registers it for cleanup after running main()
  def tempdir(self,subdir=None,autoRemove=None):
    if self._tempdir is None:
      raise RuntimeError('You can only call tempdir() from within a task.')
    d = self._tempdir
    if subdir: 
      d = op.join(d,subdir)
    autoRemove = autoRemove if autoRemove is not None else self.autoRemove
    if not self.fancyClean:
      self.fancyClean = FancyClean()
    self.fancyClean.addCreateDir(d,autoRemove)
    return d

  # tempfile(f) returns tempdir/f, and registers it for cleanup after running main()
  def tempfile(self,f=None,ext='',autoRemove=None):
    if f is None:
      rand8 = ''.join(random.SystemRandom().choice(string.ascii_letters + string.digits) for _ in range(8))      
      f = '{}_{}{}'.format(self.__class__.__name__.lower(),rand8,ext)
    f = op.join(self.tempdir(),f)
    autoRemove = autoRemove if autoRemove is not None else self.autoRemove
    if not self.fancyClean:
      self.fancyClean = FancyClean()
    self.fancyClean.addNewFile(f,autoRemove)
    return FancyTempFile(f)
  
  def getCommandLine(self):
    #return 'python runtask.py {} [...]'.format(self.__class__.__name__)
    return None

  # Get an input.
  def getInput(self,key=ALL):
    return self.myInput if key is ALL else self.myInput[key]

  # Set the input, may be called multiple times to modify/augment inputs.
  def setInput(self,*args,**kwargs):
    self.myInput.args.extend(args)
    self.myInput.kwargs.update(kwargs)
    return self
    
  def resetInput(self,*args,**kwargs):
    self.reset()
    return self.setInput(*args,**kwargs)

  # Make task callable, returns task instance with inputs set.
  __call__ = resetInput
    
  # Generate output request links, use these links only 
  # to return as output of a task, or to set as input to another task.
  def _requestOutput(self,*keys):
    if keys:
      output = FancyList()
      for k in keys:
        output.append(FancyRequest(self,k))
    else:
      output = FancyRequest(self,ALL)
    return output

  def requestOutput(self,*keys):
    output = self._requestOutput(*keys)
    return output[0] if len(keys) == 1 else output
  
  # Called before getting any output.
  def finalizeInput(self):
    # Retrieve unset inputs from configuration file or defaults
    if self.inputs:
      configArgs = self.fancyConfig.classDefaults(self.__class__.__name__) if self.fancyConfig else {}
      myInput = self.myInput.kwargs
      for key,inp in self.inputs.items():
        if not key in myInput:
          if key in configArgs:
            myInput[key] = configArgs[key]
          elif 'default' in inp:
            if hasattr(inp['default'],'__call__'):
              myInput[key] = inp['default'](myInput)
            else:
              myInput[key] = inp['default']
          else:
            raise RuntimeError('No default value found for input "{}" of task {}.'.format(key,self))
    
  # Get one or more outputs, run task if necessary.
  def getOutput(self,*keys):
    output = self._requestOutput(*keys)
    if not isinstance(output,FancyValue): output = FancyValue(output)
    pendingTasks = global_taskManager.runningTask.initRequests(output)
    if pendingTasks: global_taskManager.resolve( pendingTasks )
    return output[0] if len(keys) == 1 else output.getValue()

  # Returns the name of the output file associated with output [key].
  def outputFile(self,key):
    if key in self.myInput:
      f = self.myInput[key]
      if isinstance(f,FancyOutputFile): return f
      
  # Recursively initialize output requests and return pending tasks.
  def initRequests(self,myArgs):
    def addRequest(inKey,req): # target key, task request
      task,outKey = (req.task,req.outKey)

      # Try to use previous result.
      if outKey is ALL:
        if task.runStatus is Task_Completed:
          myArgs[inKey] = task.myOutput.getValue()
          return False
      else:
        try:
          # Use previous result.
          myArgs[inKey] = task.myOutput[outKey]
          return False
        except (KeyError,TypeError):
          # No previous result.
          if task.runStatus is Task_Completed:
            # Perhaps the previous run did not return all possible outputs, rerun.
            FancyReport.warning('Task "{}" has status completed, but requested key "{}" was not found. Will try and run the task again.'.format(task,outKey))
            task.runStatus = Task_Reset
          elif task.recycle:
            # Check for files that can be recycled
            f = task.outputFile(outKey)
            if f and op.isfile(f):
              fancyPrint('Recycling output file {} of task {}'.format(f,task))
              myArgs[inKey] = f
              return False

      fancyPrint('Task {} requests output "{}" from {}'.format(self,outKey,task))
      if not outKey in task.requests: task.requests[outKey] = set()
      task.requests[outKey].add(str(self))
      linkKey = (str(task),outKey)
      if not linkKey in self.sourceLinks: self.sourceLinks[linkKey] = []
      self.sourceLinks[linkKey].append((myArgs,inKey))
      return True

    pendingTasks = {}
    mySources = set()
    if isinstance(myArgs,FancyValue):
      if hasattr(myArgs,'args'):
        for i,v in enumerate(myArgs.args):
          if isinstance(v,FancyRequest):
            if addRequest(i,v): mySources.add(v.task)
          elif isinstance(v,FancyValue):
            pendingTasks.update( self.initRequests(v) )
      if hasattr(myArgs,'kwargs'):
        for k,v in myArgs.kwargs.items():
          if isinstance(v,FancyRequest):
            if addRequest(k,v): mySources.add(v.task)
          elif isinstance(v,FancyValue):
            pendingTasks.update( self.initRequests(v) )
      if hasattr(myArgs,'value'):
        v = myArgs.value
        if isinstance(v,FancyRequest):
          if addRequest(ALL,v): mySources.add(v.task)

    for src in mySources:
      if src.runStatus == Task_Reset:
        src.runStatus = Task_ResolvingInput
        pendingTasks.update( { str(src):src } )
        pendingTasks.update( src.initRequests(src.myInput) )

    return pendingTasks


  # Fulfill requests after running (some of) the pending tasks.
  def _fulfillRequests(self,taskCache):
    affectedTasks = {}
    for outKey,targets in self.requests.items():
      linkKey = (str(self),outKey)
      try:
        val = None if self.runStatus is Task_Failed else self.myOutput.getValue() if outKey is ALL else self.myOutput[outKey]
      except KeyError: 
        raise KeyError('Task "{}" does not have the requested output "{}"'.format(self,outKey))
      for tgtKey in targets:
        if tgtKey == str(self):
          # special case when calling self.resolve()
          tgt = self
        else:  
          tgt = taskCache[tgtKey]
          affectedTasks[tgtKey] = tgt
        for (myArgs,inKey) in tgt.sourceLinks[linkKey]:
          myArgs[inKey] = val
    return affectedTasks
        

  # Workhorse of the task, called in parallel mode where applicable.
  def main(self,*args,**kwargs):
    # OVERRIDE ME
    return FancyArgs(*args,**kwargs)
    

  # Wrapper for main().
  def _main(self):
    parentTask = global_taskManager.runningTask
    global_taskManager.runningTask = self
    self.finalizeInput()
    output = self.main(*self.myInput.args,**self.myInput.kwargs)
    self.myOutput = output if isinstance(output,FancyValue) else FancyValue(output)
    global_taskManager.runningTask = parentTask


  # Called when task is completed, to cleanup temporary files.
  def cleanup(self,taskCache):
    if not self.fancyClean: return
    (excludedFiles,excludedDirs) = self.fancyClean.cleanup(exclude=self.myOutput.tempfiles(),taskKey=str(self))
    if self.parentKey and (excludedFiles or excludedDirs):
      # Inherit tempfiles and tempdirs that could not yet be removed.
      try:
        parent = taskCache[self.parentKey]
        if not parent.fancyClean: parent.fancyClean = FancyClean()
        parent.fancyClean.update(excludedFiles,excludedDirs)
      except KeyError:
        FancyReport.warning('Key "{}" not found in task cache while cleaning up {}.\nThe task cache contains {}.'.format(self.parentKey,global_taskManager.taskCache.values()))


  def run(self,saveAs=None):
    if global_taskManager is None: raise RuntimeError('TaskManager not ready. To run a task use task.runFromCommandLine().')
    global_taskManager.run(self,saveAs)
    return self

#endclass


class FancyTaskInstance(FancyTask):
  def __init__(self,main,options={}):
    # Called when decorating a function with @FancyTask
    self.main = main.__get__(self,self.__class__)
    self.name = main.__name__
    FancyTask.__init__(self,**options)


class FancyExec(FancyTask):
  """ 
  Extended class for tasks that execute jobs outside of Python,
  with a pre-defined main method.
  """
  runParallel = True
  myEnv = None
  myCwd = None
  myProg = None
  stdout = ''
  
  def getCommandLine(self):
    try:
      cmd = self.getCommand(*self.myInput.args,**self.myInput.kwargs)
      return ' '.join([str(v) for v in cmd])
    except:
      return FancyTask.getCommandLine(self)

  # set environment variables
  def setEnv(self,env):
    self.myEnv = env
    return self
    
  # set current working directory
  def setCwd(self,cwd):
    self.myCwd = cwd
    return self
    
  def setProg(self,prog):
    self.myProg = prog
    self.name = '<{}>'.format(prog)
    return self
  
  def setTitle(self,title):
    self.title = title
    return self
    
  def getCommand(self,*args,**kwargs):
    cmd = [self.myProg] if self.myProg else []
    cmd.extend([str(v) for v in args]);
    for k,v in kwargs.items(): cmd.extend([str(k),str(v)])
    return cmd

  def returnValue(self):
    return self.myInput

  def main(self,*args,**kwargs):
    cmd = self.getCommand(*args,**kwargs)
    opts = dict(shell=False, stderr=subprocess.STDOUT)
    if self.myCwd: opts['cwd'] = self.myCwd
    if self.myEnv: opts['env'] = self.myEnv
    ##try:
    self.stdout = subprocess.check_output(cmd, **opts).decode('utf-8')
    ##except subprocess.CalledProcessError as e:
    ##  raise RuntimeError(
    ##    'message',str(e),
    ##    'type',e.__class__.__name__,
    ##    'output',e.output,
    ##    'traceback',FancyReport.traceback()
    ##  )
    fancyLog(self.stdout.split('\n'),'stdout','longtext')
    # Return the inputs, this is useful when an input represents the name of an output file.
    return self.returnValue()
#endclass


class FancyRequestItem(FancyTask):
  """ 
  Used when requesting a specific list- or dictionary-item from the output of a task.
  """
  def main(self,output,key):
    return output[key]


# TaskManager that runs tasks in serial mode.
class TaskManager():
  worker = None
  runningTask = None
  fancyLog = None
  
  def __init__(self,worker=None):
    self.taskCache = {}
    if worker: self.worker = worker
    
  def print(self,s):
    task = self.runningTask;
    if self.worker: 
      # send to main thread
      self.worker.putResult(Result_Print,s,str(task) if task else None)
    else: 
      # main thread
      print(s)
    
  def log(self,task,data,name,tp=None):
    if not task: task = self.runningTask;
    if self.worker: 
      # send to main thread
      self.worker.putResult(Result_Log,(data,name,tp),str(task) if task else None)
    else:
      # main thread
      if tp == 'task':
        self.fancyLog.logTask(task,name)
      else:
        self.fancyLog.logResult(task,data,name,tp)

  # Submit pending tasks
  def submit(self,tasks):    
    self.taskCache.update(tasks)
    for key,task in tasks.items():
      if task.runStatus == Task_ResolvingInput and task.myInput.ready():
        task.runStatus = Task_Submitted
        self.log(task,None,task.getName(),'task')
        self.onInputReady(task)
      elif task.runStatus == Task_ResolvingOutput and task.myOutput.ready():
        self.onOutputReady(task)

  # Act when the input of a task has been resolved
  def onInputReady(self,task):
    try:
      # carry out the task
      task._main()
      # output may contain unresolved subtasks (=pending tasks)
      self.onAfterMain(task)
      ##task.runStatus = Task_ResolvingOutput
      ##pendingTasks = task.initRequests(task.myOutput)
      ##if pendingTasks: self.submit(pendingTasks)
      ##else: self.onOutputReady(task)
    except (KeyboardInterrupt, SystemExit):
      raise
    except BaseException as e:
      task.runStatus = Task_Failed
      self.onOutputReady(task,FancyReport.errorTrace(e))
  
  # TESTING...
  def onAfterMain(self,task):
    ## DEBUGGING: try to free some memory
    task.myInput = FancyArgs()
    task.runStatus = Task_ResolvingOutput
    pendingTasks = task.initRequests(task.myOutput)
    if pendingTasks: self.submit(pendingTasks)
    else: self.onOutputReady(task)
              
  # Act when the output of a task has been resolved
  def onOutputReady(self,task,errorTrace=None):
    self.taskCache.pop(str(task))
    if errorTrace:
      task.runStatus = Task_Failed
      self.log(task,errorTrace,'error',tp='error')
    else:
      task.runStatus = Task_Completed
      self.log(task,task.myOutput,'output')
      task.cleanup(self.taskCache)
      affectedTasks = task._fulfillRequests(self.taskCache)
      self.submit(affectedTasks)
    
  # Run until completion of all pending tasks.
  def resolve(self,pendingTasks):
    self.submit(pendingTasks)
#endclass


# TaskManager that supports running tasks in parallel or distributed mode.
class FancyTaskManager(TaskManager):
  inputs = odict([
    ('workDir', dict( default=None,
      help='Directory to store intermediate and final results. Default: system tempdir.'
    )),
    ('logDir', dict( default=None,
      help='Directory to store logFile (fancylog.js + fancylog.html) and attachments. Default: workDir.'
    )),
    ('logLevel', dict( type=int, default=3,
      help='Log mode, 1 logs entries, 2 logs standard output, 4 logs data, 8 logs extras.'
    )),
    ('logMode', dict( type=int, default=0,
      help='32 suppresses console printing, 64 suppresses file writing, 128 overwrites existing log file. Default: 0.'
    )),
    ('autoRemove', dict( action='store_true', default=False,
      help='Automatically remove intermediate result files. Default: False (keep everything).'
    )),
    ('workerType', dict( type=assertMatch('([pPtT])'), default=('P'),
      help='Either "T" or "P": T uses multi-threading while P uses multi-processing. Use T when the pipeline mainly involves calls to external programs; use P when Python itself is used for number-crunching.'
    )),
    ('numWorkers', dict( type=assertType(int), default='auto',
      help='Number of parallel workers. Default: number of CPUs.'
    )),
    ('jobServer', dict( type=assertJobServer, default=False,
      help='Address (ip-address:port) of job server started with "python fancyserver.py -auth=abracadabra"'
    )),
    ('jobAuth', dict( default='abracadabra',
      help='Authorization key for submitting jobs to the job manager. Default: abracadabra.'
    ))
  ])
  workerPool = None
  workDir = None
  
  def __init__(self,fancyReport,workDir=None,logDir=None,logLevel=3,logMode=0,jobServer=None,jobAuth='',numWorkers=0,workerType='P'):
    # reporting (captures output if jsonrpc2 is set)
    self.report = fancyReport

    # storage
    self.workDir = workDir if workDir else op.join(tempfile.gettempdir(),'FancyWork')

    # logging
    logTo = 0
    if not (logMode & 32) and not fancyReport.rpc_id: logTo |= LogTo_Console
    if not (logMode & 64): logTo |= LogTo_File
    if (logMode & 128): logTo |= LogTo_Overwrite
    if not logDir: logDir = self.workDir
    self.fancyLog = FancyLog(logDir,logLevel,logTo)

    # parallel processing
    self.jobServer = jobServer
    self.jobAuth = jobAuth
    self.numWorkers = numWorkers
    self.workerType = workerType
    if jobServer:
      self.workerPool = RemotePool(jobServer,jobAuth)
    elif numWorkers>0:
      self.workerPool = LocalPool(numWorkers,workerType)
    self.jobCount = 0

    TaskManager.__init__(self)

  @staticmethod
  def _extendParser(p,inputs):
    for key in inputs:
      inp = inputs[key].copy()
      short = inp.pop('short') if 'short' in inp else key.lower()
      positional = inp.pop('positional') if 'positional' in inp else False
      dest = [key] if positional else ['-{}'.format(short),'--{}'.format(key)]
      # ignore argument default, defer to parseInputs
      if 'default' in inp: del inp['default']
      # overwrite argument type, defer to parseInputs
      if 'type' in inp: inp['type'] = str
      p.add_argument(*dest,**inp)
    
  @classmethod
  def _getParser(cls,TaskToRun):
    p = argparse.ArgumentParser(
      description=TaskToRun.description if TaskToRun.description else TaskToRun.title,
      formatter_class=argparse.ArgumentDefaultsHelpFormatter,
      argument_default=argparse.SUPPRESS,
      conflict_handler='resolve'
    )
    g = p.add_argument_group('TaskManager arguments')
    cls._extendParser(g,FancyConfig.inputs)
    cls._extendParser(g,FancyReport.inputs)
    cls._extendParser(g,cls.inputs)
    g = p.add_argument_group('{} module arguments'.format(TaskToRun.__name__))
    cls._extendParser(g,TaskToRun.inputs)
    return p

  # Run a task, and report either the result or errors.
  def run(self,task,saveAs=None):
    global global_taskManager
    global_taskManager = self
    try:
      self.runningTask = task
      output = task.getOutput()
      self.report.success(output)
      if saveAs:
        if op.dirname(saveAs) == '': saveAs = task.tempfile(saveAs)
        with open(saveAs,'w') as fp:
          json.dump(FancyLog.jsonify(output),fp)
    except BaseException as e:
      errorTrace = FancyReport.errorTrace(e)
      errorTrace['message'] = [
        'Fatal error in task {}.'.format(task),
        errorTrace['message']
      ]
      # FANCYLOG AND FANCYREPORT SHOULD BE INTEGRATED
      self.log(task,errorTrace,name='Fatal error',tp='error')
      #self.report.fail('Fatal error in task {}.'.format(task))

  @classmethod
  def runFromCommandLine(cls,TaskToRun,**presets):
    TaskToRun.fromCommandLine(**presets).run()
    
  # Act when all input requests of a task are resolved
  def onInputReady(self,task):
    if self.workerPool and task.runParallel:
      # Send task to worker pool
      self.workerPool.putTask(task)
      self.jobCount += 1
    else:
      TaskManager.onInputReady(self,task)

  ## Act when a remote worker delivers a task result
  ##def onResultFromQueue(self,taskKey,myOutput,fancyClean):
  ##  self.jobCount -= 1
  ##  task = self.taskCache[taskKey]
  ##  task.myOutput = myOutput
  ##  if fancyClean:
  ##    task.fancyClean = fancyClean
  ##  self.onAfterMain(task)
  ##  ##task.runStatus = Task_ResolvingOutput
  ##  ##pendingTasks = task.initRequests(task.myOutput)
  ##  ##if pendingTasks: self.submit(pendingTasks)
  ##  ##else: self.onOutputReady(task)

  # Run until completion of all pending tasks.
  def resolve(self,pendingTasks):
    self.jobCount = 0
    self.submit( pendingTasks )
    while self.jobCount > 0:
      resultCode,resultData,taskKey,workerName = self.workerPool.getResult()
      if resultCode is Result_Success:
        myOutput,fancyClean = resultData
        ##self.onResultFromQueue(taskKey,myOutput,fancyClean)
        self.jobCount -= 1
        task = self.taskCache[taskKey]
        task.myOutput = myOutput
        if fancyClean:
          task.fancyClean = fancyClean
        self.onAfterMain(task)
      elif resultCode is Result_Failed:
        runningTask,errorTrace = resultData
        errorTrace['message'] = [
          'Worker {} encountered an error while running task {}.'.format(workerName,taskKey),
          errorTrace['message']
        ]
        self.jobCount -= 1
        task = self.taskCache[taskKey]
        self.onOutputReady(task,errorTrace)
      elif resultCode is Result_Print:
        fancyPrint(resultData)
      elif resultCode is Result_Log:
        data,name,tp = resultData
        task = self.taskCache[taskKey]
        self.log(task,data,name,tp)
        

## Worker class used for parallel task execution.
class Worker():
  runId = None
  
  def __init__(self, jobQueue, resultQueue):
    self.jobQueue = jobQueue
    self.resultQueue = resultQueue
    
  def getTask(self):
    return self.jobQueue.get()
    
  def putResult(self,resultCode,resultData,taskKey):
    result = (resultCode,resultData,taskKey,self.name)
    self.resultQueue.put(result)

  def run(self):
    global global_taskManager
    task = None
    while True:
      try:    
        global_taskManager = TaskManager(worker=self)
        task,self.runId = self.getTask()
        if task is None:
          fancyPrint('No task, exiting worker.')
          break
        else:
          fancyPrint('{} Starting task {}, running at {}'.format(datetime.datetime.now().isoformat(),task,self.name))
        task._main()
        resultCode = Result_Success
        resultData = (task.myOutput,task.fancyClean)
      except BaseException as e:
        resultCode = Result_Failed
        resultData = (global_taskManager.runningTask,FancyReport.errorTrace(e))
      finally:
        self.putResult(resultCode,resultData,str(task) if task else None)        
        global_taskManager = None
        task = None
        resultCode = None
        resultData = None
        unreachable = gc.collect()
#endclass


class RemoteWorker(Worker,multiprocessing.Process):
  def __init__(self, managerAddr,managerAuth):
    multiprocessing.Process.__init__(self)
    from multiprocessing.managers import BaseManager
    class ManagerProxy(BaseManager): pass
    ManagerProxy.register('getJobQueue')
    ManagerProxy.register('getResultQueue')
    ManagerProxy.register('getControlQueue')
    fancyPrint('Connecting to job server {}:{}'.format(managerAddr[0],managerAddr[1]))
    self.manager = ManagerProxy(address=managerAddr,authkey=managerAuth)
    self.manager.connect()
    self.jobQueue = self.manager.getJobQueue()

  def getTask(self):
    return pickle.loads(self.jobQueue.get())
    
  def putResult(self,resultCode,resultData,taskKey):
    result = (resultCode,resultData,taskKey,self.name)
    resultQueue = self.manager.getResultQueue(self.runId)
    resultQueue.put(pickle.dumps(result))
#endclass


class WorkerThread(Worker,threading.Thread):
  def __init__(self, jobQueue, resultQueue):
    threading.Thread.__init__(self)
    Worker.__init__(self,jobQueue,resultQueue)
#endclass


class WorkerProcess(Worker,multiprocessing.Process):
  def __init__(self, jobQueue, resultQueue):
    multiprocessing.Process.__init__(self)
    Worker.__init__(self,jobQueue,resultQueue)
#endclass
    
    
## Maintains a pool of worker threads for workerType T,
## or a pool of worker processes for workerType P.
class LocalPool():
  def __init__(self,numWorkers,workerType):
    self.queueClass = queue.Queue if workerType=='T' else multiprocessing.Queue
    workerClass = WorkerThread if workerType=='T' else WorkerProcess
    self.jobQueue = self.queueClass()
    self.resultQueue = self.queueClass()
    for w in range(numWorkers):
      worker = workerClass(self.jobQueue,self.resultQueue)
      worker.daemon = True
      worker.start()

  def putTask(self,task):
    self.jobQueue.put((task,None))
    
  def getResult(self):
    return self.resultQueue.get()
#endclass


## Connects to a remote pool of workers, started by fancymanager.py,
class RemotePool():
  resultQueue = None
  
  def __init__(self,jobServer,jobAuth):
    self.runId = uuid.uuid4()
    class ManagerProxy(multiprocessing.managers.BaseManager): pass
    ManagerProxy.register('getJobQueue')
    ManagerProxy.register('getResultQueue')
    ManagerProxy.register('popResultQueue')
    ManagerProxy.register('getControlQueue')
    fancyPrint('Connecting to job server {}:{}.'.format(jobServer[0],jobServer[1]))
    self.manager = ManagerProxy(address=jobServer,authkey=jobAuth)
    self.manager.connect()
    multiprocessing.current_process().authkey = jobAuth
    self.jobQueue = self.manager.getJobQueue()
    self.resultQueue = self.manager.getResultQueue(self.runId)
    
  def putTask(self,task):
    self.jobQueue.put(pickle.dumps((task,self.runId)))
  
  def getResult(self):
    return pickle.loads(self.resultQueue.get())

  def getControlQueue(self):
    return selfmanager.getControlQueue(self.runId)

  def __del__(self):
    if self.resultQueue: self.manager.popResultQueue(self.runId)
#endclass
