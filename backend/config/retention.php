<?php
return ['trash_days'=>max(1,(int)env('TRASH_RETENTION_DAYS',30))];
