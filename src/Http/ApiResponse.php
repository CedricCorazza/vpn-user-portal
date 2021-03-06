<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal\Http;

use LC\Portal\Json;

class ApiResponse extends Response
{
    /**
     * XXX mixed data is not cool.
     *
     * @param bool|string|array|int|null $responseData
     */
    public function __construct(string $wrapperKey, $responseData = null, int $responseCode = 200)
    {
        $responseBody = [
            $wrapperKey => [
                'ok' => true,
            ],
        ];

        if (null !== $responseData) {
            $responseBody[$wrapperKey]['data'] = $responseData;
        }

        parent::__construct($responseCode, 'application/json');
        $this->setBody(Json::encode($responseBody));
    }
}
