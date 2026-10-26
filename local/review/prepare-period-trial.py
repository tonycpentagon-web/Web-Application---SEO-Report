import sqlite3
s=sqlite3.connect('local/review/hanns-period-before.sqlite');d=sqlite3.connect('local/review/hanns-period-trial.sqlite');s.backup(d)
