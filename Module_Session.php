<?php
namespace GDO\Session;

use GDO\Core\GDO_Module;

final class Module_Session extends GDO_Module
{
    public function getClasses()
    {
        return [GDO_Session::class];
    }
    
}
