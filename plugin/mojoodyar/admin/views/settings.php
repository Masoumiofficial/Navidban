<?php
/**
 * Settings view.
 *
 * @var array  $settings Current settings.
 * @var string $group    Settings group name.
 *
 * @package Mojoodyar
 */

defined( 'ABSPATH' ) || exit;

use Mojoodyar\Logic\Template;
use Mojoodyar\Support\Persian;
use Mojoodyar\Settings;

$mjy_secret = function ( $key ) use ( $settings ) {
	$value = (string) ( $settings[ $key ] ?? '' );
	return '' === $value ? '' : 'saved';
};
?>
<?php $this->open_screen( 'settings', 'تنظیمات موجودیار', 'پیش‌فرض‌ها آمادهٔ کارند؛ برای پیامک فقط یکی از درگاه‌ها را پر کنید' ); ?>

	<?php settings_errors( 'mojoodyar' ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( $group ); ?>

		<div class="mjy-panel">
			<h2>عمومی</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mjy-form-heading">عنوان فرم</label></th>
					<td><input id="mjy-form-heading" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_heading]" value="<?php echo esc_attr( (string) $settings['form_heading'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-form-intro">توضیح زیر عنوان</label></th>
					<td><textarea id="mjy-form-intro" class="large-text" rows="2" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_intro]"><?php echo esc_textarea( (string) $settings['form_intro'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-button">متن دکمه</label></th>
					<td><input id="mjy-button" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[button_label]" value="<?php echo esc_attr( (string) $settings['button_label'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-success">پیام موفقیت</label></th>
					<td><input id="mjy-success" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[success_message]" value="<?php echo esc_attr( (string) $settings['success_message'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">نام فروشگاه در پیام‌ها</th>
					<td><input class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[site_name]" value="<?php echo esc_attr( (string) $settings['site_name'] ); ?>" /></td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>کانال‌ها</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">فیلد نام و نام خانوادگی</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_name_field]" value="1" <?php checked( ! empty( $settings['form_name_field'] ) ); ?> /> نمایش داده شود</label>
						<p class="description">
							نام در پیامک (متغیر <code>{0}</code> و <code>{customer_name}</code>) استفاده می‌شود، در لیست انتظارها
							و خروجی CSV می‌آید، و به شما اجازه می‌دهد مشتری را تلفنی پیگیری کنید.
							اگر مشتری وارد حسابش شده باشد، از قبل پر می‌شود.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">نام اجباری باشد؟</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_name_required]" value="1" <?php checked( ! empty( $settings['form_name_required'] ) ); ?> /> بله، بدون نام ثبت نشود</label>
						<p class="description">
							اجباری بودن، ثبت‌نام بعضی مشتری‌ها را متوقف می‌کند؛ اختیاری بودن، ردیف‌های
							بی‌نام تولید می‌کند. تصمیم با شماست — پیش‌فرض «اجباری» است.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-channel-choice">اولویت کانال در فرم</label></th>
					<td>
						<select id="mjy-channel-choice" name="<?php echo esc_attr( Settings::OPTION ); ?>[channel_choice]">
							<?php
							$mjy_choices = array(
								'sms_first'   => 'پیامک (پیش‌فرض) — مناسب بازار ایران',
								'email_first' => 'ایمیل',
							);
							$mjy_current = (string) $settings['channel_choice'];
							foreach ( $mjy_choices as $mjy_value => $mjy_label ) {
								printf(
									'<option value="%s"%s>%s</option>',
									esc_attr( $mjy_value ),
									selected( $mjy_current, $mjy_value, false ),
									esc_html( $mjy_label )
								);
							}
							?>
						</select>
						<p class="description">
							فقط وقتی اثر دارد که هر دو فیلد پایین روشن باشند؛ تعیین می‌کند کدام گزینه از قبل
							انتخاب شده باشد.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">فیلد ایمیل در فرم</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_email_field]" value="1" <?php checked( ! empty( $settings['form_email_field'] ) ); ?> /> نمایش داده شود</label>
						<p class="description">
							خاموش کنید تا مشتری فقط کادر شمارهٔ موبایل را ببیند.
							<?php if ( ! \Mojoodyar\Notifier::email_ready() ) : ?>
								<strong>ایمیل در حال حاضر خاموش است، پس این فیلد روی سایت دیده نمی‌شود.</strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">فیلد موبایل در فرم</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_phone_field]" value="1" <?php checked( ! empty( $settings['form_phone_field'] ) ); ?> /> نمایش داده شود</label>
						<p class="description">
							خاموش کنید تا مشتری فقط کادر ایمیل را ببیند.
							<?php if ( ! \Mojoodyar\Notifier::sms_ready() ) : ?>
								<strong>پیامک پیکربندی نشده (یا خاموش است)، پس فیلد موبایل روی سایت دیده نمی‌شود تا وقتی درگاه را کامل کنید.</strong>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">ایمیل</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[email_enabled]" value="1" <?php checked( ! empty( $settings['email_enabled'] ) ); ?> /> فعال</label>
						<p class="description">از تابع ایمیل وردپرس (wp_mail) استفاده می‌شود؛ هیچ سرویس بیرونی در میان نیست.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-from-email">نشانی فرستنده</label></th>
					<td>
						<input id="mjy-from-email" class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[from_email]" value="<?php echo esc_attr( (string) $settings['from_email'] ); ?>" placeholder="noreply@example.com" />
						<p class="description">
							بهتر است دامنهٔ همین نشانی با دامنهٔ سایت یکی باشد؛ فرستندهٔ ناهم‌خوان، رایج‌ترین
							دلیل «ارسال شد ولی ایمیل نیامد» است. خالی بگذارید تا ایمیل مدیر سایت
							(<code dir="ltr"><?php echo esc_html( (string) get_option( 'admin_email', '' ) ); ?></code>) استفاده شود.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-from-name">نام فرستنده</label></th>
					<td><input id="mjy-from-name" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[from_name]" value="<?php echo esc_attr( (string) $settings['from_name'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">پیامک</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_enabled]" value="1" <?php checked( ! empty( $settings['sms_enabled'] ) ); ?> /> فعال</label>
						<p class="description">تا وقتی درگاه پیامک پیکربندی نشود، پیامک ارسال نمی‌شود.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-gateway">درگاه پیامک</label></th>
					<td>
						<select id="mjy-gateway" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_gateway]">
							<?php
							$mjy_gateways = array(
								'melipayamak' => 'ملی‌پیامک',
								'ippanel'     => 'آی‌پی‌پنل',
								'kavenegar'   => 'کاوه‌نگار',
								'generic'     => 'درگاه عمومی REST',
							);
							foreach ( $mjy_gateways as $mjy_key => $mjy_label ) :
								?>
								<option value="<?php echo esc_attr( $mjy_key ); ?>" <?php selected( (string) $settings['sms_gateway'], $mjy_key ); ?>><?php echo esc_html( $mjy_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>متن پیام‌ها</h2>
			<p class="mjy-muted">
				جای‌نگهدارهای مجاز:
				<?php foreach ( Template::placeholders() as $mjy_ph ) : ?>
					<code>{<?php echo esc_html( $mjy_ph ); ?>}</code>
				<?php endforeach; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mjy-subject">موضوع ایمیل</label></th>
					<td><input id="mjy-subject" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[email_subject]" value="<?php echo esc_attr( (string) $settings['email_subject'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-email-body">متن ایمیل</label></th>
					<td><textarea id="mjy-email-body" class="large-text code" rows="7" name="<?php echo esc_attr( Settings::OPTION ); ?>[email_body]"><?php echo esc_textarea( (string) $settings['email_body'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-sms-body">متن پیامک</label></th>
					<td>
						<textarea id="mjy-sms-body" class="large-text" rows="3" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_template]"><?php echo esc_textarea( (string) $settings['sms_template'] ); ?></textarea>
						<p class="description">
							متغیرهای متن آزاد: <code>{customer_name}</code> نام مشتری، <code>{product_name}</code> نام محصول،
							<code>{product_url}</code> نشانی صفحه، <code>{site_name}</code> نام فروشگاه.
							اگر نام مشتری ثبت نشده باشد، جای آن «همراه گرامی» می‌آید.
						</p>
						<p class="description">پیوند لغو اشتراک در هر پیام اجباری است؛ آن را حذف نکنید. هر ۷۰ کاراکتر یک پیامک حساب می‌شود.</p>
					</td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>رضایت و تأیید دومرحله‌ای</h2>
			<p class="description" style="max-width:640px;">
				پیش‌فرض تازه: تأیید دومرحله‌ای <strong>خاموش</strong> است؛ مشتری یک‌بار ثبت می‌کند و اعلان موجودی مستقیم می‌رسد.
				اگر روی این سایت ثبت‌نام الکی زیاد شده، روشنش کنید. هزینه‌اش این است که تا وقتی مشتری پیوند تأیید را باز نکند،
				اعلانی برایش نمی‌رود؛ بعضی مشتری‌ها این را «پیام نیامد» برداشت می‌کنند.
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">چک‌باکس رضایت</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[consent_required]" value="1" <?php checked( ! empty( $settings['consent_required'] ) ); ?> /> اجباری باشد</label>
						<p class="description">پیش‌فرض: خاموش (چک‌باکس نمایش داده نمی‌شود). اگر روشن کنید، ثبت بدون تیک انجام نمی‌شود.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-consent-label">متن رضایت</label></th>
					<td><input id="mjy-consent-label" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[consent_label]" value="<?php echo esc_attr( (string) $settings['consent_label'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-privacy">نشانی سیاست حریم خصوصی</label></th>
					<td>
						<input id="mjy-privacy" class="large-text" type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[privacy_url]" value="<?php echo esc_attr( (string) $settings['privacy_url'] ); ?>" placeholder="خالی = صفحهٔ رسمی حریم خصوصی وردپرس" />
					</td>
				</tr>
				<tr>
					<th scope="row">تأیید دومرحله‌ای</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[double_opt_in]" value="1" <?php checked( ! empty( $settings['double_opt_in'] ) ); ?> /> فعال</label>
						<p class="description">
							خاموش (پیش‌فرض): ثبت یک‌مرحله‌ای است و اعلان موجودی مستقیم ارسال می‌شود — کم‌ترین اصطکاک برای مشتری.
							روشن: تا وقتی مشتری پیوند تأیید را باز نکند، هیچ اعلانی برایش نمی‌رود؛ جلوی ثبت‌نام الکی را می‌گیرد.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-ttl">اعتبار پیوند تأیید (ساعت)</label></th>
					<td><input id="mjy-ttl" type="number" min="1" max="168" name="<?php echo esc_attr( Settings::OPTION ); ?>[confirm_ttl_hours]" value="<?php echo esc_attr( (string) $settings['confirm_ttl_hours'] ); ?>" /></td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>زمان‌بندی و صف</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">اعلام به تعداد موجودی</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[fair_sending]" value="1" <?php checked( ! empty( $settings['fair_sending'] ) ); ?> /> فعال</label>
						<p class="description">اگر ۵ عدد موجود شد و ۲۰۰ نفر در انتظار باشند، فقط به ۵ نفر اول خبر می‌دهد. بقیه برای بازگشت بعدی می‌مانند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">اعلام برای پیش‌سفارش (backorder)</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[notify_backorders]" value="1" <?php checked( ! empty( $settings['notify_backorders'] ) ); ?> /> پیش‌سفارش هم «موجود» شمرده شود</label></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-batch">اندازهٔ دسته</label></th>
					<td><input id="mjy-batch" type="number" min="1" max="500" name="<?php echo esc_attr( Settings::OPTION ); ?>[queue_batch_size]" value="<?php echo esc_attr( (string) $settings['queue_batch_size'] ); ?>" /> <span class="description">پیش‌فرض ۵۰، سقف ۵۰۰.</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-attempts">حداکثر تلاش برای هر پیام</label></th>
					<td>
						<input id="mjy-attempts" type="number" min="1" max="5" name="<?php echo esc_attr( Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( (string) $settings['max_attempts'] ); ?>" />
						<span class="description">
							پیش‌فرض ۳. اگر پیام ناموفق شد، با فاصلهٔ ۱۵ دقیقه، ۱ ساعت و ۶ ساعت دوباره تلاش می‌شود
							و بعد از این تعداد، «ناموفق» می‌ماند. خطاهای تنظیماتی (طول مقدار، الگوی نامعتبر) از
							این شمارش جدا هستند و تلاشی مصرف نمی‌کنند.
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-rate">حداکثر ارسال در دقیقه</label></th>
					<td><input id="mjy-rate" type="number" min="1" max="600" name="<?php echo esc_attr( Settings::OPTION ); ?>[queue_per_minute]" value="<?php echo esc_attr( (string) $settings['queue_per_minute'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row">بازهٔ مجاز ارسال</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[send_window_enabled]" value="1" <?php checked( ! empty( $settings['send_window_enabled'] ) ); ?> /> فعال</label>
						از <input type="number" min="0" max="23" name="<?php echo esc_attr( Settings::OPTION ); ?>[send_window_start]" value="<?php echo esc_attr( (string) $settings['send_window_start'] ); ?>" />
						تا <input type="number" min="0" max="23" name="<?php echo esc_attr( Settings::OPTION ); ?>[send_window_end]" value="<?php echo esc_attr( (string) $settings['send_window_end'] ); ?>" />
						<p class="description">بیرون از این بازه، پیام‌ها در صف می‌مانند و در اولین ساعت مجاز ارسال می‌شوند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-reconcile">فاصلهٔ پایش تطبیقی (دقیقه)</label></th>
					<td><input id="mjy-reconcile" type="number" min="5" max="1440" name="<?php echo esc_attr( Settings::OPTION ); ?>[reconcile_interval]" value="<?php echo esc_attr( (string) $settings['reconcile_interval'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-retention">نگهداریٔ رکورد (روز)</label></th>
					<td><input id="mjy-retention" type="number" min="0" max="3650" name="<?php echo esc_attr( Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" /> <span class="description">۰ = هرگز پاک نشود.</span></td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>درگاه‌های پیامک</h2>

			<h3>ملی‌پیامک</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">نام کاربری</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_user]" value="<?php echo esc_attr( (string) $settings['melipayamak_user'] ); ?>" /></td></tr>
				<tr><th scope="row">رمز</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_pass]" value="" placeholder="<?php echo '' === $mjy_secret( 'melipayamak_pass' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
				<tr><th scope="row">شمارهٔ خط</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_from]" value="<?php echo esc_attr( (string) $settings['melipayamak_from'] ); ?>" /></td></tr>
				<tr>
					<th scope="row">شناسهٔ الگو (bodyId)</th>
					<td>
						<input class="regular-text" dir="ltr" inputmode="numeric" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_body_id]" value="<?php echo esc_attr( (string) $settings['melipayamak_body_id'] ); ?>" placeholder="مثلاً 123456" />
						<p class="description">
							اختیاری. برای خطوط خدماتی، ارسال فقط با «الگو» انجام می‌شود. با پر کردن این فیلد،
							افزونه به‌جای متن کامل، <strong>فقط مقدارها</strong> را با جداکنندهٔ <code>;</code> می‌فرستد.
						</p>
					</td>
				</tr>
			</table>

			<?php
			$mjy_pattern_stored = (string) $settings['sms_pattern_template'];
			$mjy_pattern_text   = \Mojoodyar\Logic\SmsPattern::resolve( $mjy_pattern_stored, (string) $settings['site_name'] );
			$mjy_findings       = \Mojoodyar\Logic\SmsPattern::validate( $mjy_pattern_text, (string) $settings['site_name'] );
			$mjy_sample_context = array(
				'customer_name'   => 'مریم',
				'product_name'    => 'کفش دویدن نایک',
				'product_url'     => 'https://example.com/product/nike-air-zoom-pegasus-41',
				'sms_product_url' => '', // filled below from the real settings
				'price'           => '۲٬۴۵۰٬۰۰۰ تومان',
				'site_name'       => (string) $settings['site_name'],
			);

			// Real numbers, not an example domain: the admin is deciding here
			// whether the link fits the operator's per-variable budget.
			$mjy_full_link = (string) \Mojoodyar\Notifier::product_url( 12 );
			$mjy_short_link = (string) \Mojoodyar\Notifier::product_short_url( 12 );
			$mjy_sample_context['product_url']     = $mjy_full_link;
			$mjy_sample_context['sms_product_url'] = 'off' === (string) $settings['sms_link_mode'] ? '' : $mjy_short_link;
			$mjy_value_limit                       = \Mojoodyar\Logic\SmsPattern::limit();
			$mjy_site_link                         = (string) \Mojoodyar\Notifier::site_link();
			?>

			<h3>متن الگو (برای خطوط خدماتی)</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">متن تأییدشدهٔ الگو</th>
					<td>
						<textarea id="mjy-sms-pattern" class="large-text" rows="3" dir="rtl" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_pattern_template]" placeholder="<?php echo esc_attr( \Mojoodyar\Logic\SmsPattern::default_template( (string) $settings['site_name'] ) ); ?>"><?php echo esc_textarea( $mjy_pattern_stored ); ?></textarea>
						<p class="description">
							همان متنی را بگذارید که در پنل ملی‌پیامک تأیید شده است. اگر خالی بماند، متن پیشنهادی پایین
							استفاده می‌شود. جای هر مقدار متغیر، <code>{0}</code>، <code>{1}</code> … بگذارید —
							نه نام متغیر.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">متغیرهای این افزونه</th>
					<td>
						<ul class="mjy-pattern-vars">
							<?php foreach ( \Mojoodyar\Logic\SmsPattern::labels() as $mjy_index => $mjy_label ) : ?>
								<li><code><?php echo esc_html( '{' . $mjy_index . '}' ); ?></code> <?php echo esc_html( $mjy_label ); ?></li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							ترتیب ارسال همان ترتیب شماره است: مقدار <code>{0}</code> اول، بعد <code>{1}</code> و…
							اگر شماره‌گذاری پرش داشته باشد یا با متغیر تمام شود، درگاه پیام را رد می‌کند.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-link-mode">نشانی محصول در پیامک</label></th>
					<td>
						<select id="mjy-link-mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_link_mode]">
							<?php foreach ( array( 'short' => 'نشانی کوتاه محصول (پیشنهادی)', 'site' => 'نشانی اصلی سایت', 'full' => 'نشانی کامل محصول', 'off' => 'بدون نشانی' ) as $mjy_mode => $mjy_mode_label ) : ?>
								<option value="<?php echo esc_attr( $mjy_mode ); ?>" <?php selected( (string) $settings['sms_link_mode'], $mjy_mode ); ?>>
									<?php echo esc_html( $mjy_mode_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<span class="mjy-link-compare">
								نشانی کوتاه محصول:
								<code dir="ltr"><?php echo esc_html( $mjy_short_link ); ?></code>
								<strong><?php echo esc_html( Persian::number( \Mojoodyar\Support\Text::length( $mjy_short_link ) ) ); ?></strong> کاراکتر ·
								نشانی کامل:
								<code dir="ltr"><?php echo esc_html( $mjy_full_link ); ?></code>
								<strong><?php echo esc_html( Persian::number( \Mojoodyar\Support\Text::length( $mjy_full_link ) ) ); ?></strong> کاراکتر ·
								نشانی اصلی سایت:
								<code dir="ltr"><?php echo esc_html( $mjy_site_link ); ?></code>
								<strong><?php echo esc_html( Persian::number( \Mojoodyar\Support\Text::length( $mjy_site_link ) ) ); ?></strong> کاراکتر
							</span>
							هیچ سرویس بیرونی در کار نیست؛ همهٔ این پیوندها روی همین سایت ساخته می‌شوند.
						</p>
						<div class="mjy-pattern-help mjy-pattern-help--warn">
							<p>
								<strong>«نشانی اصلی سایت» همیشه در سقف جا می‌شود، ولی خریدار را به صفحهٔ اصلی می‌برد نه به همان محصول</strong>
								— مشتری باید خودش کالا را پیدا کند و نرخ تبدیل پایین می‌آید. اگر این را انتخاب می‌کنید،
								بهتر است همین نشانی را به‌صورت <em>متن ثابت</em> داخل الگوی پنل پیامک بگذارید
								(مثلاً «… موجود شد — <?php echo esc_html( (string) parse_url( $mjy_site_link, PHP_URL_HOST ) ); ?>»)
								و <code>{2}</code> را از الگو بردارید: آن‌وقت هیچ محدودیت طولی روی لینک وجود ندارد،
								چون دیگر متغیر نیست.
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-short-domain">دامنهٔ کوتاه (اختیاری)</label></th>
					<td>
						<input id="mjy-short-domain" class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_short_domain]" value="<?php echo esc_attr( (string) $settings['sms_short_domain'] ); ?>" placeholder="etd.ir" />
						<p class="description">
							اگر دامنهٔ کوتاهی دارید که به همین فروشگاه اشاره می‌کند، اینجا بگذارید تا نشانی
							به <code dir="ltr">https://etd.ir/?p=12</code> تبدیل شود. خالی بماند، از دامنهٔ خود سایت استفاده می‌شود.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mjy-value-limit">سقف کاراکتر هر متغیر</label></th>
					<td>
						<input id="mjy-value-limit" type="number" min="<?php echo (int) \Mojoodyar\Logic\SmsPattern::MIN_VALUE_LENGTH; ?>" max="<?php echo (int) \Mojoodyar\Logic\SmsPattern::MAX_VALUE_LIMIT; ?>" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_max_value_length]" value="<?php echo esc_attr( (string) $settings['sms_max_value_length'] ); ?>" />
						<p class="description">
							مقدار پیش‌فرض ۲۵ است و <strong>تخمین محافظه‌کارانه</strong> است، نه قانون منتشرشدهٔ اپراتور.
							عددی را بگذارید که اپراتور خودتان می‌پذیرد (تا ۷۰). نام مشتری و نام محصول خودکار تا همین
							سقف کوتاه می‌شوند؛ مقدار بلندترِ کوتاه‌نشدنی (یعنی نشانی محصول) پیش از ارسال جلوی پیام را می‌گیرد.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">روش جای‌گذاری</th>
					<td>
						<div class="mjy-pattern-help">
							<p>
								<strong>۱.</strong> در پنل ملی‌پیامک → «ارسال الگو» → «متن‌های من» یک متن بسازید و همان را
								تأیید بگیرید. متن نهایی همان چیزی است که مشتری می‌بیند؛ کد افزونه فقط مقدارها را پر می‌کند.
							</p>
							<p>
								<strong>۲.</strong> برای هر مقداری که می‌خواهید در پیام بیاید، یک متغیر بگذارید: <code>{0}</code>، <code>{1}</code>، <code>{2}</code> …
								شماره‌گذاری از صفر و بدون پرش.
							</p>
							<p>
								<strong>۳.</strong> متن را با <strong>نام فروشگاه</strong> تمام کنید (متن ثابت، نه متغیر).
								متغیر در انتها = رد شدن الگو.
							</p>
							<p>
								<strong>۴.</strong> الگوهایی مثل «<code>{0} باتشکر</code>» یا «<code>{0} گوگل</code>»
								تأیید نمی‌شوند؛ متن باید محتوای واقعی داشته باشد.
							<p>
								<strong>۴.۵.</strong> اگر <code>{2}</code> (نشانی محصول) در الگوست، حتماً «نشانی کوتاه» را
							روشن بگذارید و اگر باز هم از سقف بیشتر بود، یا دامنهٔ کوتاه بگذارید یا <code>{2}</code> را
							از الگو بردارید؛ وگرنه درگاه پیام را با <code>InvalidData</code> رد می‌کند.
						</p>
						<p class="description">
							<strong>۵.</strong> هر مقدار حداکثر <strong><?php echo esc_html( \Mojoodyar\Support\Persian::number( \Mojoodyar\Logic\SmsPattern::MAX_VALUE_LENGTH ) ); ?></strong>
								کاراکتر باشد؛ این محدودیت رایج اپراتورها است و شایع‌ترین دلیل پاسخ
								<code>InvalidData</code> از درگاه. افزونه نام مشتری و نام محصول را خودکار
								تا همین طول کوتاه می‌کند (نشانی محصول را نه).
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">متن پیشنهادی</th>
					<td>
						<code class="mjy-pattern-sample" dir="rtl"><?php echo esc_html( \Mojoodyar\Logic\SmsPattern::default_template( (string) $settings['site_name'] ) ); ?></code>
						<p class="description">
							این متن را عیناً در پنل درگاه ثبت کنید (نام فروشگاه خودتان در انتهایش هست)، تأیید بگیرید و
							شناسهٔ آن را در فیلد <code>bodyId</code> بالا بگذارید.
						</p>
						<p class="description">
							نمونهٔ مقداری که افزونه برای همین متن می‌فرستد:
							<code dir="ltr"><?php echo esc_html( \Mojoodyar\Logic\SmsPattern::payload( $mjy_pattern_text, $mjy_sample_context ) ); ?></code>
						</p>
						<p class="description">
							طول هر مقدار در همین سایت (اندازه‌گیری‌شده، نه نمونهٔ فرضی):
							<?php foreach ( \Mojoodyar\Logic\SmsPattern::indexes( $mjy_pattern_text ) as $mjy_var_index ) : ?>
								<?php
								$mjy_var_length = \Mojoodyar\Support\Text::length( \Mojoodyar\Logic\SmsPattern::raw( $mjy_var_index, $mjy_sample_context ) );
								$mjy_var_over   = $mjy_var_length > $mjy_value_limit;
								?>
								<code dir="ltr"<?php echo $mjy_var_over ? ' class="mjy-over"' : ''; ?>><?php echo esc_html( '{' . $mjy_var_index . '}=' . $mjy_var_length ); ?></code>
							<?php endforeach; ?>
							— سقف مجاز
							<code dir="ltr"><?php echo esc_html( (string) $mjy_value_limit ); ?></code>
							کاراکتر.
							اگر عددی سرخ شد، همان متغیر پیش از ارسال جلوی پیام را می‌گیرد.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">بررسی متن</th>
					<td>
						<?php if ( empty( $mjy_findings ) ) : ?>
							<p class="mjy-pattern-ok">هیچ ایرادی پیدا نشد: شماره‌گذاری درست است، متن با متغیر تمام نمی‌شود و نام فروشگاه در انتها هست.</p>
						<?php else : ?>
							<ul class="mjy-pattern-findings">
								<?php foreach ( $mjy_findings as $mjy_finding ) : ?>
									<li class="is-<?php echo esc_attr( $mjy_finding['level'] ); ?>">
										<strong><?php echo 'error' === $mjy_finding['level'] ? 'خطا' : 'هشدار'; ?>:</strong>
										<?php echo esc_html( $mjy_finding['message'] ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description">
								این بررسی بر اساس قواعد منتشرشدهٔ درگاه‌هاست؛ تصمیم نهایی با بازبین پنل شماست.
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h3>آی‌پی‌پنل</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">کلید API</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[ippanel_token]" value="" placeholder="<?php echo '' === $mjy_secret( 'ippanel_token' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
				<tr><th scope="row">شمارهٔ فرستنده</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[ippanel_from]" value="<?php echo esc_attr( (string) $settings['ippanel_from'] ); ?>" /></td></tr>
			</table>

			<h3>کاوه‌نگار</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">کلید API</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[kavenegar_api_key]" value="" placeholder="<?php echo '' === $mjy_secret( 'kavenegar_api_key' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
				<tr><th scope="row">شمارهٔ فرستنده</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[kavenegar_sender]" value="<?php echo esc_attr( (string) $settings['kavenegar_sender'] ); ?>" /></td></tr>
			</table>

			<h3>درگاه عمومی REST</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">نشانی درگاه</th>
					<td>
						<input class="large-text" dir="ltr" type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[generic_url]" value="<?php echo esc_attr( (string) $settings['generic_url'] ); ?>" placeholder="https://" />
						<p class="description">فقط https و فقط دامنهٔ عمومی. نشانی‌های محلی، خصوصی و IP رد می‌شوند.</p>
					</td>
				</tr>
				<tr><th scope="row">توکن (Bearer)</th><td><input class="large-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[generic_token]" value="" placeholder="<?php echo '' === $mjy_secret( 'generic_token' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
				<tr>
					<th scope="row">قالب داده</th>
					<td>
						<textarea class="large-text code" dir="ltr" rows="4" name="<?php echo esc_attr( Settings::OPTION ); ?>[generic_payload]"><?php echo esc_textarea( (string) $settings['generic_payload'] ); ?></textarea>
						<p class="description">جای‌نگهدارها: <code>{to}</code> و <code>{message}</code>. مقدارها به‌صورت JSON امن جای‌گذاری می‌شوند.</p>
					</td>
				</tr>
			</table>

		</div>
		<div class="mjy-panel">
			<h2>حریم خصوصی و پیشرفته</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">حذف داده هنگام حذف افزونه</th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> /> فعال</label>
						<p class="description">پیش‌فرض خاموش. با روشن کردن، هنگام حذف افزونه همهٔ جدول‌ها و تنظیمات پاک می‌شوند.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">ثبت لاگ اشکال‌زدایی</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[debug_log]" value="1" <?php checked( ! empty( $settings['debug_log'] ) ); ?> /> فعال</label></td>
				</tr>
			</table>

		</div>
		<div class="mjy-actions">
			<?php submit_button( 'ذخیرهٔ تنظیمات', 'primary', 'submit', false ); ?>
			<p>تغییرات پس از ذخیره، فوراً روی فرم فروشگاه اعمال می‌شود.</p>
		</div>
	</form>

	<?php $this->close_screen(); ?>
