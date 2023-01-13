<?php

namespace GocqAdapter;

use OneBot\Util\Singleton;

class GocqRetcodeConverter
{
    use Singleton;

    public function convertRetCode11To12(int $retcode): int
    {
        return $retcode !== 0 ? 10000 : 0;
    }
}
