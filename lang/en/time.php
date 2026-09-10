<?php

/*
|--------------------------------------------------------------------------
| Clock faces (C1.18)
|--------------------------------------------------------------------------
|
| The server sends punch times pre-formatted, because the reading that matters
| is the wall clock in the company's zone rather than a moment a handset would
| re-render in its own. So the meridiem has to be translated here rather than on
| the phone. `App\Support\Clock` is the only thing that reads these.
|
| Not Carbon's own locale data: `format('A')` is fixed English and `isoFormat`
| would pull in a translation set nobody in this project has reviewed, for one
| word that appears on every attendance row in the product.
|
*/

return [

    'am' => 'AM',
    'pm' => 'PM',

];
