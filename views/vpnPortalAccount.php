<?php $this->layout('base', ['activeItem' => 'account']); ?>
<?php $this->start('content'); ?>
    <h2><?=$this->t('User Info'); ?></h2>
    <table>
        <tr>
            <th><?=$this->t('ID'); ?></th>
            <td><code><?=$this->e($userInfo->id()); ?></code></td>
            <td></td>
        </tr>
        <?php if ('FormPdoAuthentication' === $authMethod): ?>
            <tr>
                <th></th>
                <td><a href="passwd"><?=$this->t('Change Password'); ?></a></td>
                <td></td>
            </tr>
        <?php endif; ?>

        <?php if (0 !== count($userGroups)): ?>
        <tr>
            <th><?=$this->t('Group Membership(s)'); ?></th>
            <td>
                <ul class="simple">
                    <?php foreach ($userGroups as $userGroup): ?>
                        <li><?=$this->e($userGroup); ?></li>
                    <?php endforeach; ?>
                </ul>
            </td>
            <td></td>
        </tr>
        <?php endif; ?>

        <?php if (0 !== count($twoFactorMethods)): ?>
        <tr>
            <th><?=$this->t('Two-factor Authentication'); ?></th>
            <td>
                <?php if ($hasTotpSecret): ?>
                    <span class="plain"><?=$this->t('TOTP'); ?></span>
                <?php else: ?>
                    <a href="two_factor_enroll"><?=$this->t('Enroll'); ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <?php if (0 !== count($authorizedClients)): ?>
    <h2><?=$this->t('Authorized Applications'); ?></h2>
    <table>
        <thead>
            <tr><th><?=$this->t('Name'); ?></th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($authorizedClients as $client): ?>
            <tr>
                <td><span title="<?=$this->e($client['client_id']); ?>"><?php if ($client['display_name']): ?><?=$this->e($client['display_name']); ?><?php else: ?><em><?=$this->t('Unregistered Client'); ?></em><?php endif; ?></span></td>
                <td class="text-right">
                    <form method="post" action="removeClientAuthorization">
                        <input type="hidden" name="client_id" value="<?=$this->e($client['client_id']); ?>">
                        <input type="hidden" name="scope" value="<?=$this->e($client['scope']); ?>">
                        <button class="error"><?=$this->t('Revoke'); ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php $this->stop(); ?>