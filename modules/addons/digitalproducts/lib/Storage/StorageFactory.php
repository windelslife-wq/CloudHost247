<?php
namespace DigitalProducts\Storage;

class StorageFactory
{
    public static function make() { return new LocalPrivateStorage(); }
}
