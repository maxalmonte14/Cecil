<?php

declare(strict_types=1);

/*
 * This file is part of Cecil.
 *
 * Copyright (c) Arnaud Ligny <arnaud@ligny.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cecil\Command\ShowContent;

use RecursiveTreeIterator;

/**
 * Replace Filepath by Filename.
 */
class FilenameRecursiveTreeIterator extends RecursiveTreeIterator
{
    /**
     * @return mixed
     */
    public function current(): mixed
    {
        return str_replace(
            (string) $this->getInnerIterator()->current(),
            substr(strrchr((string) $this->getInnerIterator()->current(), DIRECTORY_SEPARATOR), 1),
            parent::current()
        );
    }
}
