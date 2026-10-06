<?php
namespace DigitalProducts\Core;

if (!class_exists('DigitalProducts\\Core\\DigitalProductsException')) {
    class DigitalProductsException extends \RuntimeException {}
    class AuthorizationException extends DigitalProductsException {}
    class ValidationException extends DigitalProductsException
    {
        protected $errors;
        public function __construct($message, array $errors = [])
        {
            parent::__construct($message);
            $this->errors = $errors;
        }
        public function errors() { return $this->errors; }
    }
    class StorageException extends DigitalProductsException {}
    class RateLimitException extends DigitalProductsException {}
}
