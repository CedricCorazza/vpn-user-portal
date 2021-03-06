<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2019, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal\Http;

use fkooman\Otp\Exception\OtpException;
use fkooman\Otp\Totp;
use fkooman\SeCookie\SessionInterface;
use LC\Portal\Http\Exception\HttpException;
use LC\Portal\Storage;
use LC\Portal\TplInterface;

class TwoFactorModule implements ServiceModuleInterface
{
    /** @var \LC\Portal\Storage */
    private $storage;

    /** @var \fkooman\SeCookie\SessionInterface */
    private $session;

    /** @var \LC\Portal\TplInterface */
    private $tpl;

    public function __construct(Storage $storage, SessionInterface $session, TplInterface $tpl)
    {
        $this->storage = $storage;
        $this->session = $session;
        $this->tpl = $tpl;
    }

    public function init(Service $service): void
    {
        $service->post(
            '/_two_factor/auth/verify/totp',
            function (Request $request, array $hookData): Response {
                if (!\array_key_exists('auth', $hookData)) {
                    throw new HttpException('authentication hook did not run before', 500);
                }
                /** @var UserInfo */
                $userInfo = $hookData['auth'];
                $userId = $userInfo->getUserId();

                $this->session->delete('_two_factor_verified');

                $totpKey = InputValidation::totpKey($request->requirePostParameter('_two_factor_auth_totp_key'));
                $redirectTo = $request->requirePostParameter('_two_factor_auth_redirect_to');

                try {
                    $totp = new Totp($this->storage);
                    $totp->verify($userId, $totpKey);
                    $this->session->regenerate();
                    $this->session->set('_two_factor_verified', $userId);

                    return new RedirectResponse($redirectTo, 302);
                } catch (OtpException $e) {
                    $this->storage->addUserMessage($userId, 'notification', 'OTP validation failed: '.$e->getMessage());

                    // unable to validate the OTP
                    return new HtmlResponse(
                        $this->tpl->render(
                            'twoFactorTotp',
                            [
                                '_two_factor_user_id' => $userId,
                                '_two_factor_auth_invalid' => true,
                                '_two_factor_auth_error_msg' => $e->getMessage(),
                                '_two_factor_auth_redirect_to' => $redirectTo,
                            ]
                        )
                    );
                }
            }
        );
    }
}
