<?php
define('ssl_module_file_loaded','');

class Ssl_Module extends Module {

		
	public function __construct($app,$name='') {
		if($name=='') $name='vps module';
		parent::__construct($app,$name);
	}

function adjust_ssl(){
	/*
#### steps for ssl adjust
openssl genrsa -out server.key 2048
# prepare LocalServer.cnf
openssl req -new -key server.key -out server.csr -config LocalServer.cnf
# send your server.csr to your Certificate company.
# upload key files in ehcp,

	 * */
	$alanlar=array("step","_insert",'country_name','state','city','company','unit_name','common_name','email','SSLCertificateKeyFile');
	foreach($alanlar as $al) global $$al;
	$this->app->getVariable($alanlar);
	$this->app->requireAdmin(); # şimdilik fazla güvenlik almadım, ondan...
	$domainname=$this->app->chooseDomain(__FUNCTION__,$domainname);

	# howto: http://www.digicert.com/ssl-certificate-installation-apache.htm
	$waitstr="Your ssl config is being built now, <a href='?op=".__FUNCTION__."&step=2'>wait until finished, retry here</a>";
	$file1=$this->app->ehcpdir."/upload/LocalServer_$domainname.cnf";
	$file2=$this->app->ehcpdir."/upload/ssl_generated_$domainname"; 


	if(!$step){
		@unlink($file1); # remove file if exists
		@unlink($file2);
		
		$params=array(
			array('country_name','default'=>'TR','righttext'=>'Country Name (2 letter code) [ex:TR]'),
			array('state','default'=>'My State','righttext'=>'State or Province Name (full name) [Some-State], ex: Yorks'),
			array('city','default'=>'My City (ehcp)'),
			array('company','default'=>'My Company (ehcp)','righttext'=>'optional, Your organization name, i.e, company'),
			array('unit_name','default'=>'Unit Name','righttext'=>'Organizational Unit Name (eg, section)'),
			array('common_name','default'=>"*.$domainname",'righttext'=>'(www.yourdomain.com, fqdn, or *.yourdomain.com to generate for all subdomains) <b><big></big>THIS IS MOST IMPORTANT PART</b> this should be the Fully Qualified Domain Name (FQDN) or the web address for which you plan to use your Certificate, e.g. the area of your site you wish customers to connect to using SSL. For example, an SSL Certificate issued for yourdomain.com will not be valid for secure.yourdomain.com, unless you use wildcard *.yourdomain.com'),
			array('email','default'=>$this->app->conf['adminemail'],'righttext'=>'optional'),
			array('step','hidden','default'=>'1')
		);

		$this->app->output.="This is experimental, will be improved:<br>Step 1: CSR Generation:".inputform5($params)."  Skip to <a href='?op=".__FUNCTION__."&step=2'>step 2</a> if you already generated your key files before.";
	} elseif($step==1) {
		$out="
[ req ]
prompt			= no
distinguished_name	= server_distinguished_name

[ server_distinguished_name ]
commonName		= $common_name
stateOrProvinceName	= $state
countryName		= $country_name
emailAddress		= $email
organizationName	= $company
organizationalUnitName	= $unit_name";

		file_put_contents($file1,$out);
		$this->app->addDaemonOp('generate_ssl_config1','',$domainname,$file1,'generate_ssl_config');
		$this->app->output.=$waitstr;
	} elseif($step==2){
		if(file_exists($file2)) $this->app->output.="Now, put/send your CSR (Certificate Signing Request) to your Certificate Company: <hr><pre>".file_get_contents("{$this->app->ehcpdir}/upload/server_$domainname.csr")."</pre><hr> After sending this to your certificate company, you will get two files, one is domain certificate file, other is Company cert chain file,<br>After that Your may proceed to <a href='?op=".__FUNCTION__."&step=3'>step 3</a> for importing crt files. ";
		else $this->app->output.=$waitstr;
	} elseif($step==3) {

		$params=array(
			array('SSLCertificateFile','fileupload','righttext'=>'should be your domain certificate file (eg. your_domain_name.crt)'),
			array('SSLCertificateChainFile','fileupload','righttext'=>'should be the Chain certificate file, eg, certificate of certificate seller (eg. DigiCertCA.crt) '),
			array('step','hidden','default'=>'4')
		);

		#if(!file_exists($file2)) $params[]=array('SSLCertificateKeyFile','fileupload','righttext'=>'should be the key file generated when you created the CSR, (your_private.key)'); # if generated externally, for uploading to server.
		# else $params[]=array('SSLCertificateKeyFile','hidden','default'=>'server.key'); # if generated by ehcp.

		$this->app->output.="This is experimental, will be improved: <br><br>Now, upload files provided by your Certificate company: <br>Step 2:".inputform5($params);
	} elseif($step==4) {
		$files=array('SSLCertificateFile','SSLCertificateChainFile');
		$success=True;

		foreach($files as $file) {
			$path=$this->app->ehcpdir."/upload/";
			$success=$success && $this->app->upload_file($file,$path.$file."_$domainname.crt");
		}
		$success=$success && $this->app->addDaemonOp("syncdomains",'xx',$domainname);

		return $this->app->ok_err_text($success,"Successfull","Failed");

	}

}

/*
<VirtualHost 192.168.0.1:443>
DocumentRoot /var/www/html2
ServerName www.yourdomain.com
SSLEngine on
SSLCertificateFile /path/to/your_domain_name.crt
SSLCertificateKeyFile /path/to/your_private.key
SSLCertificateChainFile /path/to/DigiCertCA.crt
</VirtualHost>
 */


function generate_ssl_config1($domainname){
	$this->app->requireCommandLine();
	print __FUNCTION__.": Generating ssl fonfig for domain: $domainname";

	$this->app->generate_server_key_file();
	$dom_keyfile="/etc/ehcp/sslkeyfile_$domainname"; # domain keyfile is saved, to prevernt accidental deletion of server keyfile, hence to prevent need for cert re-generation
	# bu key dosyaları tüm domainler için aslında aynı olacak, ama ilerde serverin keyi bişekilde değişirse diye.. hepsini saklıyorum.
	if(!file_exists($dom_keyfile)) copy($this->app->sslkeyfile,$dom_keyfile);

	passthru2("openssl req -new -key $dom_keyfile -out {$this->app->ehcpdir}/upload/server_$domainname.csr -config {$this->app->ehcpdir}/upload/LocalServer_$domainname.cnf"); # prepare certificate signing request (csr)
	#passthru2("openssl req -nodes -key $this->app->sslkeyfile -out $this->app->ehcpdir/upload/server_$domainname.csr -config $this->app->ehcpdir/upload/LocalServer_$domainname.cnf");

	#passthru2("openssl x509 -req -days 365 -in $this->app->ehcpdir/server.csr -signkey $this->app->ehcpdir/server.key -out $this->app->ehcpdir/server.crt");  # signing - this will be done in a trusted cert authority.
	#passthru2("cp -vf $this->app->ehcpdir/server.crt /etc/ssl/certs/"); 
	file_put_contents($this->app->ehcpdir."/upload/ssl_generated_$domainname", ""); 
	return True;
}



		
} # end class



?>