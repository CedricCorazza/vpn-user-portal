<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal\Http;

class RedirectResponse extends Response
{
    public function __construct(string $redirectUri, int $statusCode = 302)
    {
        // XXX validate URL here
        parent::__construct($statusCode);
        $this->addHeader('Location', $redirectUri);
    }
}
