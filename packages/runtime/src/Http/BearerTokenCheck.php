<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\Server\Request;
use SensitiveParameter;

final readonly class BearerTokenCheck
{
    private const string BEARER_TOKEN = '~\ABearer +(\S+)\z~i';

    public function __construct(
        #[SensitiveParameter]
        private ?string $token,
    ) {}

    public function admitsRequest(Request $request): bool
    {
        if ($this->token === null) {
            return true;
        }

        $authorization = $request->getHeader('authorization');

        return $authorization !== null
            && preg_match(self::BEARER_TOKEN, $authorization, $matches) === 1
            && hash_equals($this->token, $matches[1]);
    }
}
