"""Stop only recorded local Stage 8 processes after checking their actual Windows command lines."""
import ctypes,json,sqlite3
from ctypes import wintypes
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def stop_owned(stage,role):
 stage=Path(stage).resolve()
 if stage.parent!=(ROOT/'.tools').resolve() or stage.name not in ['stage8-local-windows','stage8-local-restored'] or not (stage/'.synthetic-stage8').is_file():raise ValueError('Not a known disposable staging root.')
 pids=json.loads((stage/'processes.json').read_text());pid=pids.get(role)
 if not pid:return False
 kernel=ctypes.WinDLL('kernel32',use_last_error=True);kernel.OpenProcess.restype=wintypes.HANDLE;kernel.OpenProcess.argtypes=[wintypes.DWORD,wintypes.BOOL,wintypes.DWORD];kernel.CloseHandle.argtypes=[wintypes.HANDLE];kernel.TerminateProcess.argtypes=[wintypes.HANDLE,wintypes.UINT]
 handle=kernel.OpenProcess(0x1001,False,pid)
 if not handle:return False
 try:
  length=wintypes.ULONG();nt=ctypes.WinDLL('ntdll');query=nt.NtQueryInformationProcess;query.argtypes=[wintypes.HANDLE,wintypes.ULONG,ctypes.c_void_p,wintypes.ULONG,ctypes.POINTER(wintypes.ULONG)];query(handle,60,None,0,ctypes.byref(length));buffer=ctypes.create_string_buffer(length.value)
  if query(handle,60,buffer,length.value,ctypes.byref(length))!=0:raise ValueError('Cannot verify process command line.')
  class Unicode(ctypes.Structure):_fields_=[('length',wintypes.USHORT),('max',wintypes.USHORT),('buffer',ctypes.c_void_p)]
  item=Unicode.from_buffer(buffer);command=ctypes.wstring_at(item.buffer,item.length//2).replace('\\','/').lower()
  expected=(ROOT/'ops/staging'/('router.php' if role=='web' else 'console.php')).as_posix().lower()
  if expected not in command or (role=='worker' and 'queue:work' not in command) or (role=='web' and ('127.0.0.1:'+('8288' if stage.name.endswith('restored') else '8188')) not in command):raise ValueError('Recorded PID no longer matches the synthetic process.')
  if role=='worker':
   conn=sqlite3.connect((stage/'database.sqlite').as_uri()+'?mode=ro',uri=True);reserved=conn.execute('select count(*) from jobs where reserved_at is not null').fetchone()[0];conn.close()
   if reserved:raise ValueError('Refusing to terminate a worker with a reserved job; use graceful restart.')
  if not kernel.TerminateProcess(handle,0):raise OSError('Stop failed.')
  return True
 finally:kernel.CloseHandle(handle)
if __name__=='__main__':
 import sys
 if sys.argv[1:]!=['stop-disposable']:raise SystemExit('Only stop-disposable is supported. No file deletion is performed.')
 for name in ['stage8-local-windows','stage8-local-restored']:
  stage=ROOT/'.tools'/name
  if not stage.exists():continue
  for role in ['worker','web']:print(name,role,stop_owned(stage,role))