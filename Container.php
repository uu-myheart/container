<?php

namespace Curia\Container;

use ArrayAccess;
use Psr\Container\ContainerInterface;

class Container // implements ArrayAccess, ContainerInterface
{
	public function hello()
	{
		echo 'Hello, DI';
	}
}