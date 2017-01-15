<?php
/**
 * 将Typecho的附件上传至七牛云存储
 * 
 * @package QiniuRCS
 * @author AtaLuZiK
 * @version 0.1
 * @link http://arthas.info/
 */
 
class QiniuRCS_Plugin implements Typecho_Plugin_Interface
{
	public static function activate()
	{
		Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('QiniuRCS_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('QiniuRCS_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('QiniuRCS_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('QiniuRCS_Plugin', 'attachmentHandle');
        return _t('插件已经激活，需先配置七牛的信息！');
	}
	
	
	public static function deactivate()
	{
		return _t('七牛插件已被禁用！');
	}
	
	
	// control panel
	public static function config(Typecho_Widget_Helper_Form $form)
    {
    	$bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', null, null, _t('空间名称：'));
        $form->addInput($bucket->addRule('required', _t('空间名不能为空！')));

        $accesskey = new Typecho_Widget_Helper_Form_Element_Text('accesskey', null, null, _t('AccessKey：'));
        $form->addInput($accesskey->addRule('required', _t('AccessKey 不能为空！')));

        $sercetkey = new Typecho_Widget_Helper_Form_Element_Text('sercetkey', null, null, _t('SecretKey：'));
        $form->addInput($sercetkey->addRule('required', _t('SecretKey 不能为空！')));

        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', null, 'http://', _t('绑定域名：'), _t('以 http:// 开头，结尾不要加 / ！'));
        $form->addInput($domain->addRule('required', _t('请填写空间绑定的域名！'))->addRule('url', _t('您输入的域名格式错误！')));
	}
	
	
	public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
	}
	
	
	public static function modifyHandle($content, $file)
    {
		return self::uploadHandle($file, $content);
	}
	
	
	public static function deleteHandle(array $content)
	{
		require_once 'autoload.php';

		$auth = self::initQiniuSDK();
		
		$bucketMgr = new Qiniu\Storage\BucketManager($auth);
		$bucketMgr->delete(self::getConfig()->bucket, self::preparePath($content['attachment']->path));
	}
	
	
	public static function attachmentHandle(array $content)
	{
		$options = Typecho_Widget::widget('Widget_Options');
        return Typecho_Common::url(self::preparePath($content['attachment']->path), 'http://static.arthas.info');
	}
	
	
	public static function uploadHandle($file, $oldFileContent = null)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::getSafeName($file['name']);

        if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
            return false;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $date = new Typecho_Date($options->gmtTime);
		$path = $date->year . '/' . $date->month;

        //获取文件名
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $path . '/' . $fileName;

        if (!isset($file['tmp_name']) && isset($file['bytes'])) {
        	// 为什么会出现这种情况我也不清楚
        	$file['tmp_name'] = tmpnam(sys_get_temp_dir(), 'TEU');
            if (!file_put_contents($file['tmp_name'], $file['bytes'])) {
                return false;
            }
        } else if (!isset($file['tmp_name'])) {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($path);
        }
		
		
		// upload to Qiniu
		$options = self::getConfig();
		$auth = self::initQiniuSDK();
		// generate token for upload
		$token = $auth->uploadToken($options->bucket);
		
		if ($oldFileContent !== null) {
			$path = self::preparePath($oldFileContent['attachment']->path);
			$bucketMgr = new Qiniu\Storage\BucketManager($auth);
			$bucketMgr->delete($options->bucket, $path);
		}
		$uploadMgr = new Qiniu\Storage\UploadManager();
		list($ret, $err) = $uploadMgr->putFile($token, $path, $file['tmp_name']);
		// delete the tempfile
		@unlink($file['tmp_name']);
		if ($err !== null) {
			// Fix later...
			var_dump($err);
		}

        //返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR) . '/' . $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => Typecho_Common::mimeContentType($path)
        );
    }


	/**
     * 获取安全的文件名 
     * 
     * @param string $name 
     * @static
     * @access private
     * @return string
     */
    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
    
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }
    
	
	private static function preparePath($path)
	{
		$count = 1;
		return str_replace('/usr/uploads/', '', $path, $count);
	}
	
	
	private static function initQiniuSDK()
	{
		require_once 'autoload.php';
		
		$options = self::getConfig();
		return new Qiniu\Auth($options->accesskey, $options->sercetkey);
	}
	
	
	private static function getConfig()
	{
		return Typecho_Widget::widget('Widget_Options')->plugin('QiniuRCS');
	}
}
