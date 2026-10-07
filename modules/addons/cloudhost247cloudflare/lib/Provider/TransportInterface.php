<?php
namespace CloudHost247\Cloudflare\Provider;
interface TransportInterface
{
    /** @return array{status:int,headers:array,body:string} */
    public function send($method, $url, array $headers, $body, $timeoutSeconds);
}
