<?php
/**
 * Settings view.
 *
 * @var array  $settings Current settings.
 * @var string $group    Settings group name.
 *
 * @package Navidban
 */

defined( 'ABSPATH' ) || exit;

use Navidban\Logic\Template;
use Navidban\Settings;

$nvb_secret = function ( $key ) use ( $settings ) {
	$value = (string) ( $settings[ $key ] ?? '' );
	return '' === $value ? '' : 'saved';
};
?>
<?php $this->open_screen( 'settings', 'تنظیمات نویدبان', 'پیش‌فرض‌ها آمادهٔ کارند؛ برای پیامک فقط یکی از درگاه‌ها را پر کنید' ); ?>

	<?php settings_errors( 'navidban' ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( $group ); ?>

		<h2>عمومی</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nvb-form-heading">عنوان فرم</label></th>
				<td><input id="nvb-form-heading" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_heading]" value="<?php echo esc_attr( (string) $settings['form_heading'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-form-intro">توضیح زیر عنوان</label></th>
				<td><textarea id="nvb-form-intro" class="large-text" rows="2" name="<?php echo esc_attr( Settings::OPTION ); ?>[form_intro]"><?php echo esc_textarea( (string) $settings['form_intro'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-button">متن دکمه</label></th>
				<td><input id="nvb-button" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[button_label]" value="<?php echo esc_attr( (string) $settings['button_label'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-success">پیام موفقیت</label></th>
				<td><input id="nvb-success" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[success_message]" value="<?php echo esc_attr( (string) $settings['success_message'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row">نام فروشگاه در پیام‌ها</th>
				<td><input class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[site_name]" value="<?php echo esc_attr( (string) $settings['site_name'] ); ?>" /></td>
			</tr>
		</table>

		<h2>کانال‌ها</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nvb-channel-choice">اولویت کانال در فرم</label></th>
				<td>
					<select id="nvb-channel-choice" name="<?php echo esc_attr( Settings::OPTION ); ?>[channel_choice]">
						<?php
						$nvb_choices = array(
							'sms_first'   => 'پیامک (پیش‌فرض) — مناسب بازار ایران',
							'email_first' => 'ایمیل',
							'both'        => 'هر دو؛ مشتری خودش انتخاب کند',
						);
						$nvb_current = (string) $settings['channel_choice'];
						foreach ( $nvb_choices as $nvb_value => $nvb_label ) {
							printf(
								'<option value="%s"%s>%s</option>',
								esc_attr( $nvb_value ),
								selected( $nvb_current, $nvb_value, false ),
								esc_html( $nvb_label )
							);
						}
						?>
					</select>
					<p class="description">
						این گزینه فقط وقتی اثر دارد که <strong>هر دو</strong> کانال واقعاً پیکربندی شده باشند.
						اگر پیامک را روشن کرده‌اید ولی کلید درگاه را خالی گذاشته‌اید، فرم به ایمیل برمی‌گردد تا
						کانالی که کار نمی‌کند به مشتری پیشنهاد نشود.
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
				<th scope="row"><label for="nvb-from-email">نشانی فرستنده</label></th>
				<td>
					<input id="nvb-from-email" class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[from_email]" value="<?php echo esc_attr( (string) $settings['from_email'] ); ?>" placeholder="noreply@example.com" />
					<p class="description">
						بهتر است دامنهٔ همین نشانی با دامنهٔ سایت یکی باشد؛ فرستندهٔ ناهم‌خوان، رایج‌ترین
						دلیل «ارسال شد ولی ایمیل نیامد» است. خالی بگذارید تا ایمیل مدیر سایت
						(<code dir="ltr"><?php echo esc_html( (string) get_option( 'admin_email', '' ) ); ?></code>) استفاده شود.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-from-name">نام فرستنده</label></th>
				<td><input id="nvb-from-name" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[from_name]" value="<?php echo esc_attr( (string) $settings['from_name'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row">پیامک</th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_enabled]" value="1" <?php checked( ! empty( $settings['sms_enabled'] ) ); ?> /> فعال</label>
					<p class="description">تا وقتی درگاه پیامک پیکربندی نشود، پیامک ارسال نمی‌شود.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-gateway">درگاه پیامک</label></th>
				<td>
					<select id="nvb-gateway" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_gateway]">
						<?php
						$nvb_gateways = array(
							'melipayamak' => 'ملی‌پیامک',
							'ippanel'     => 'آی‌پی‌پنل',
							'kavenegar'   => 'کاوه‌نگار',
							'generic'     => 'درگاه عمومی REST',
						);
						foreach ( $nvb_gateways as $nvb_key => $nvb_label ) :
							?>
							<option value="<?php echo esc_attr( $nvb_key ); ?>" <?php selected( (string) $settings['sms_gateway'], $nvb_key ); ?>><?php echo esc_html( $nvb_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h2>متن پیام‌ها</h2>
		<p class="nvb-muted">
			جای‌نگهدارهای مجاز:
			<?php foreach ( Template::placeholders() as $nvb_ph ) : ?>
				<code>{<?php echo esc_html( $nvb_ph ); ?>}</code>
			<?php endforeach; ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nvb-subject">موضوع ایمیل</label></th>
				<td><input id="nvb-subject" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[email_subject]" value="<?php echo esc_attr( (string) $settings['email_subject'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-email-body">متن ایمیل</label></th>
				<td><textarea id="nvb-email-body" class="large-text code" rows="7" name="<?php echo esc_attr( Settings::OPTION ); ?>[email_body]"><?php echo esc_textarea( (string) $settings['email_body'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-sms-body">متن پیامک</label></th>
				<td>
					<textarea id="nvb-sms-body" class="large-text" rows="3" name="<?php echo esc_attr( Settings::OPTION ); ?>[sms_template]"><?php echo esc_textarea( (string) $settings['sms_template'] ); ?></textarea>
					<p class="description">پیوند لغو اشتراک در هر پیام اجباری است؛ آن را حذف نکنید. هر ۷۰ کاراکتر یک پیامک حساب می‌شود.</p>
				</td>
			</tr>
		</table>

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
				<th scope="row"><label for="nvb-consent-label">متن رضایت</label></th>
				<td><input id="nvb-consent-label" class="large-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[consent_label]" value="<?php echo esc_attr( (string) $settings['consent_label'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-privacy">نشانی سیاست حریم خصوصی</label></th>
				<td>
					<input id="nvb-privacy" class="large-text" type="url" name="<?php echo esc_attr( Settings::OPTION ); ?>[privacy_url]" value="<?php echo esc_attr( (string) $settings['privacy_url'] ); ?>" placeholder="خالی = صفحهٔ رسمی حریم خصوصی وردپرس" />
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
				<th scope="row"><label for="nvb-ttl">اعتبار پیوند تأیید (ساعت)</label></th>
				<td><input id="nvb-ttl" type="number" min="1" max="168" name="<?php echo esc_attr( Settings::OPTION ); ?>[confirm_ttl_hours]" value="<?php echo esc_attr( (string) $settings['confirm_ttl_hours'] ); ?>" /></td>
			</tr>
		</table>

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
				<th scope="row"><label for="nvb-batch">اندازهٔ دسته</label></th>
				<td><input id="nvb-batch" type="number" min="1" max="500" name="<?php echo esc_attr( Settings::OPTION ); ?>[queue_batch_size]" value="<?php echo esc_attr( (string) $settings['queue_batch_size'] ); ?>" /> <span class="description">پیش‌فرض ۵۰، سقف ۵۰۰.</span></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-rate">حداکثر ارسال در دقیقه</label></th>
				<td><input id="nvb-rate" type="number" min="1" max="600" name="<?php echo esc_attr( Settings::OPTION ); ?>[queue_per_minute]" value="<?php echo esc_attr( (string) $settings['queue_per_minute'] ); ?>" /></td>
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
				<th scope="row"><label for="nvb-reconcile">فاصلهٔ پایش تطبیقی (دقیقه)</label></th>
				<td><input id="nvb-reconcile" type="number" min="5" max="1440" name="<?php echo esc_attr( Settings::OPTION ); ?>[reconcile_interval]" value="<?php echo esc_attr( (string) $settings['reconcile_interval'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="nvb-retention">نگهداریٔ رکورد (روز)</label></th>
				<td><input id="nvb-retention" type="number" min="0" max="3650" name="<?php echo esc_attr( Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" /> <span class="description">۰ = هرگز پاک نشود.</span></td>
			</tr>
		</table>

		<h2>درگاه‌های پیامک</h2>

		<h3>ملی‌پیامک</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">نام کاربری</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_user]" value="<?php echo esc_attr( (string) $settings['melipayamak_user'] ); ?>" /></td></tr>
			<tr><th scope="row">رمز</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_pass]" value="" placeholder="<?php echo '' === $nvb_secret( 'melipayamak_pass' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
			<tr><th scope="row">شمارهٔ خط</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_from]" value="<?php echo esc_attr( (string) $settings['melipayamak_from'] ); ?>" /></td></tr>
			<tr><th scope="row">شناسهٔ الگو (bodyId)</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[melipayamak_body_id]" value="<?php echo esc_attr( (string) $settings['melipayamak_body_id'] ); ?>" /> <span class="description">اختیاری؛ برای ارسال خدماتی/الگویی.</span></td></tr>
		</table>

		<h3>آی‌پی‌پنل</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">کلید API</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[ippanel_token]" value="" placeholder="<?php echo '' === $nvb_secret( 'ippanel_token' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
			<tr><th scope="row">شمارهٔ فرستنده</th><td><input class="regular-text" dir="ltr" name="<?php echo esc_attr( Settings::OPTION ); ?>[ippanel_from]" value="<?php echo esc_attr( (string) $settings['ippanel_from'] ); ?>" /></td></tr>
		</table>

		<h3>کاوه‌نگار</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">کلید API</th><td><input class="regular-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[kavenegar_api_key]" value="" placeholder="<?php echo '' === $nvb_secret( 'kavenegar_api_key' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
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
			<tr><th scope="row">توکن (Bearer)</th><td><input class="large-text" dir="ltr" type="password" name="<?php echo esc_attr( Settings::OPTION ); ?>[generic_token]" value="" placeholder="<?php echo '' === $nvb_secret( 'generic_token' ) ? '' : 'ذخیره شده — برای تغییر، مقدار جدید بگذارید'; ?>" autocomplete="new-password" /></td></tr>
			<tr>
				<th scope="row">قالب داده</th>
				<td>
					<textarea class="large-text code" dir="ltr" rows="4" name="<?php echo esc_attr( Settings::OPTION ); ?>[generic_payload]"><?php echo esc_textarea( (string) $settings['generic_payload'] ); ?></textarea>
					<p class="description">جای‌نگهدارها: <code>{to}</code> و <code>{message}</code>. مقدارها به‌صورت JSON امن جای‌گذاری می‌شوند.</p>
				</td>
			</tr>
		</table>

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

		<?php submit_button( 'ذخیرهٔ تنظیمات' ); ?>
	</form>

	<?php $this->close_screen(); ?>
