<?php

namespace SprykerEngine\Zed\Kernel;

use SprykerEngine\Shared\Kernel\AbstractFactory;

class Factory extends AbstractFactory
{

    /**
     * @var string
     */
    protected $classNamePattern = '\\{{namespace}}\\Zed\\{{bundle}}{{store}}\\';

}