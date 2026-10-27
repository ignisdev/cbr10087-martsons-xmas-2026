import Text from '@gravityforms/components/react/admin/elements/Text';
import type { LicenseData, LicensedProductType } from '../../../types';
import { format, addDays, isBefore } from 'date-fns';
import { __ } from '@wordpress/i18n';
import LicenseStatus from '../LicenseStatus';
import { addUtmParams } from '../../../helpers/urls';

interface LicenseInfoProps {
	license: LicenseData;
	type: LicensedProductType;
	/** Shown in place of the masked key, e.g. when the product is covered by a bundle license. */
	keyText?: string;
}

// The masked key at all three lengths; the container queries in LicenseBox.css show one of them.
const MaskedKey = ({ licenseKey }: { licenseKey: string }) => (
	<>
		{licenseKey.slice(0, 2)}
		<span className="license-box__key-dots-long">{'•'.repeat(26)}</span>
		<span className="license-box__key-dots-medium">{'•'.repeat(16)}</span>
		<span className="license-box__key-dots-short">{'•'.repeat(8)}</span>
		{licenseKey.slice(-4)}
	</>
);

const LicenseInfo = ({ license, type, keyText }: LicenseInfoProps) => {
	const renderPluginLimit = () => {
		if (type === 'perk') {
			return (
				<div className="license-box__stat">
					<Text size="text-sm" color="comet">Perks</Text>
					<Text size="text-sm" weight="medium">
						{Object.entries(license.registered_products).length}/
						{!license.registered_products_limit ? '∞' : license.registered_products_limit}
					</Text>
				</div>
			);
		}

		if (type === 'connect') {
			return (
				<div className="license-box__stat">
					<Text size="text-sm" color="comet">Connections</Text>
					<Text size="text-sm" weight="medium">
						{Object.entries(license.registered_products).length}/
						{!license.registered_products_limit ? '∞' : license.registered_products_limit}
					</Text>
				</div>
			);
		}

		return null;
	};

	return (
		<div className="license-box__details">
			<div className="license-box__stat license-box__stat--key">
				<Text size="text-sm" color="comet">{__('License Key', 'spellbook')}</Text>
				{keyText ? (
					<Text size="text-sm">
						{keyText}
						{/* Invisible masked key so this column is as wide as on a card showing a real key, keeping Status/Sites/Expires aligned across cards. */}
						<span className="license-box__key-value license-box__key-spacer" aria-hidden="true">
							<MaskedKey licenseKey="xxxxxx" />
						</span>
					</Text>
				) : (
					<Text size="text-sm" customClasses="license-box__key-value">
						{license.key ? <MaskedKey licenseKey={license.key} /> : __('No License Key', 'spellbook')}
					</Text>
				)}
			</div>
			<div className="license-box__stat">
				<Text size="text-sm" color="comet">{__('Status', 'spellbook')}</Text>
				<LicenseStatus license={license} />
				{
					license.status === 'expired' && (
						<a
							href={addUtmParams(license.extend_url, {
								component: 'license-info',
								text: 'renew-expired'
							})}
							target="_blank"
							style={{ fontWeight: '500', fontSize: '14px' }}
						>
							{__('Renew License', 'spellbook')}
						</a>
					)
				}
			</div>
				<div className="license-box__stat">
					<Text size="text-sm" color="comet">{__('Sites', 'spellbook')}</Text>
					<Text
						size="text-sm"
						weight="medium"
						color={license.site_count != null && license.site_limit != null && license.site_count >= license.site_limit && license.site_limit > 0 ? 'warning' : undefined}
					>
						{license.site_count == null || license.site_limit == null
							? '—'
							: license.site_limit === 0
								? `${license.site_count}/∞`
								: `${license.site_count}/${license.site_limit}`
						}
					</Text>
				</div>
			{renderPluginLimit()}
			<div className="license-box__stat">
				<Text size="text-sm" color="comet">
					{
						license.status === 'expired' ? __('Expired', 'spellbook') : __('Expires', 'spellbook')
					}
				</Text>
				<Text
					size="text-sm"
					weight="medium"
					color={license.expiration && license.expiration !== 'lifetime' && isBefore(new Date(license.expiration), addDays(new Date(), 30)) ? 'warning' : undefined}
				>
					{(() => {
						if (!license.expiration) return __('N/A', 'spellbook');
						if (license.expiration === 'lifetime') return __('Never', 'spellbook');

						const expirationDate = new Date(license.expiration);

						return format(expirationDate, 'MMM d, yyyy');
					})()}
				</Text>
			</div>
		</div>
	);
};

export default LicenseInfo;
