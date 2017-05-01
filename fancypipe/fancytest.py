from fancypipe import *

class TaskA(FancyTask):
  def main(self):
    return FancyDict(key1='A')
    
class TaskB(FancyTask):
  runParallel = False
  
  def main(self,x):
    output = dict(key1='B',x=x)
    if 'Q' in self.requests: output['Q'] = 'And here is Q'
    fancyPrint('Running TaskB')
    return output

class TaskC(FancyExec):
  runParallel = True

  def main(self):
    FancyExec.main(self)
    return self.stdout

class TaskD(FancyTask):
  def main(self):
    resultA = TaskA().requestOutput()
    key1,Q = TaskB().setInput(resultA).requestOutput('key1','Q')
    return FancyList([
      resultA,
      key1,
      Q,
      TaskC().setProg('ls').requestOutput()
    ])

if __name__ == '__main__':
  TaskD.fromCommandLine().run()
