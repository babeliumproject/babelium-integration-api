[Babelium]: http://babeliumproject.com
[Babelium Standalone site readme]: http://https://github.com/babeliumproject/flex-standalone-site

#Babelium integration API
[Babelium][] is an open source video platform aimed at second language learning. Language learners are able to record their voice using a browser and the cuepoint-constrained videos.

These instructions describe how to install the integration API to an existing Babelium instance. The integration API exposes a subset of the functions available to Babelium using a RPC-API interface. 

**Table of contents**
- [Obtaining the source](#obtaining-the-source)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
	- [Compile the standalone video player](#compile-the-standalone-video-player)
- [Making requests to the API](#making-requests-to-the-api)
- [Troubleshooting & support](#troubleshooting--support)
	- [Support](#support)
	- [Babelium Error 403. Wrong authorization credentials](#babelium-error-403-wrong-authorization-credentials)
	- [Babelium Error 400. Malformed request error](#babelium-error-400-malformed-request-error)
	- [Babelium Error 500. Internal server error](#babelium-error-500-internal-server-error)
	- [Moodle server is behind a firewall](#moodle-server-is-behind-a-firewall)

##Obtaining the source
To run the development version of Babelium first clone the git repository.

	$ git clone git://github.com/babeliumproject/babelium-integration-api.git babelium-integration-api

Now the entire project should be in the `babelium-integration-api/` directory.

##Prerequisites
* Babelium server instance
* Babelium's standalone video player

##Installation

If you are using your own Babelium server and want to enable Moodle instances to access the exercises stored there, you have to take additional steps, such as placing some API files and compiling a special version of the video player.

Copy the Moodle API files and the Moodle site registration script:

	$ cd babelium-integration-api
	$ cp -r api <babelium_home>/
	$ cp -r css <babelium_home>/
	$ cp moodleapi.php <babelium_home>/

Copy the video merging script to the Babelium script folder:

	$ cd babelium-integration-api
	$ cp script/* <babelium_script_directory>/

Apply the provided SQL patch to enable Moodle site registration

	$ mysql -u <babeliumdbuser> -p
	> use <babeliumdbname>;
	> source babelium-integration-api/sql/moodle_patch.sql;

Check if the paths in `<babelium_script_directory>/VideoCollage.php` are right.

Ensure the owner of `<babelium_script_directory>/VideoCollage.php` has write permissions in the `<red5_directory>/webapps/<appname>/streams/responses` dicretory.

Copy the standalone video player to the Babelium home directory (the player is available in other repository, please read below).

	$ cd babelium-flex-embeddable-player/dist
	$ cp babeliumPlayer.* <babelium_directory>/

**NOTE:** If you need help compiling the standalone player read the next point.

Following these steps you should be able to register Moodle instances in your Babelium server.

###Compile the standalone video player
Babelium needs a special version of the video player to support embedding in Moodle and other systems. These are the steps you need to take to compile the embeddable player from the source code.


To install and configure the prerequisites see [Babelium Standalone site readme][].

Then, fill the `build.properties` file for the embeddable player:

	$ cd babelium-flex-embeddable-player
	$ cp build.properties.template build.properties
	$ vi build.properties

This table describes the purpose of the property fields you should fill:

<table>
 <tr><th>Property</th><th>Description</th></tr>
 <tr><td>FLEX_HOME</td><td>The home directory of your Flex SDK installation.</td></tr>
 <tr><td>LOCALE_BUNDLES</td><td>The UI language packs to include when building the platform. All available languages are included by default. To choose only a subset of the languages, write a comma-separated list of locale codes. Locale codes have the following format: <strong>es_ES</strong> (es=Spanish, ES=Spain).</td></tr>
 <tr><td>BASE</td><td>The local path of the cloned repository (e.g. /home/babelium/git/babelium-flex-embeddable-player).</td></tr>
 <tr><td>WEB_DOMAIN</td><td>The web domain for the platform (e.g. www.babeliumproject.com).</td></tr>
 <tr><td>WEB_ROOT</td><td>The path to the web root of the platform (e.g. /var/www/babelium) </td></tr>
 <tr><td>RED5_PATH</td><td>The path to the streaming server (e.g. /var/red5).</td></tr>
 <tr><td>RED5_APPNAME</td><td>The name of the app that is going to perform the streaming job. By default <strong>vod</strong>.</td></tr>
</table>

You can leave the rest of the fields unchanged. These additional fields are mainly for filling the `Config.php` file needed for the service calls. If you want to know more about the purpose of these fields please check the [Babelium Standalone site readme][].

Once you are done editing, run ant to build:

	$ ant

The compiled files are placed in the `dist` folder.

Copy the standalone video player to the Babelium home directory.

	$ cd babelium-flex-embeddable-player/dist
	$ cp babeliumPlayer.* <babelium_home>/

Finally, don't forget to update your Apache's configuration file by adding the following line:

	Header set Access-Control-Allow-Origin "*"

This will allow your embeddable-player to use remote JavaScript calls, even if the called scripts are located 
on a a different server.

**NOTE:** using "*" means you give access to any host and that could lead to some attacks. We use this wildcard because in our demo server we let users from any domain to sign-up for a Moodle API key, and thus, can't determine the origin beforehand. If you are part of an institution you can limit the access control to your domains to have less security risks.

##Making requests to the API
API requests are made sending HTTP POST requests to the API's endpoint. The payload of the request should contain the following fields:

<table>
 <tr><th>Field</th><th>Description</th></tr>
 <tr><td>method</td><td>Name of the requested API function (e.g. <b>admGetResponseById</b>)</td></tr>
 <tr><td>parameters</td><td>The parameters to pass to the function (e.g. <b>responseId=32</b>)</td></tr>
 <tr><td>header</td><td>Additional data that serves to identify the request</br></br>
 <table>
 <tr><td>	date</td><td>	Request timestamp formatted using DATE_RFC1123 (e.g. <b>Fri, 12 Jan 2014 13:18:02 +0100</b>)</td></tr>
 <tr><td>	authorization</td><td>A concatenation of a prefix, the client's access key and a signed payload. The resulting string looks like this: <b>BMP access_key:signed_payload</b>.</td></tr>
 </table>
</td></tr>
</table>
The following table describes the process to build the **signed_payload** that is part of the authorization signature.
<table>
<tr><th>signed_payload</th></tr>
<tr><td>The signed payload contains the request method, the request date and the request origin. These data should be put into an UTF-8 encoded string using this format.</br>
</br>
	METHOD\n</br>
	DATE\n</br>
	ORIGIN</br>
</br>
This resulting string should be hashed using <b>HMAC-SHA256</b> and the <b>secret_access_key</b>.</br>
</br>
Lastly, the output of the HMAC must be encoded to produce a <b>BASE64</b> hash.
</td></tr>
</table>

In addition to the request payload you need to send valid REFERER and ORIGIN headers with your request. 

The response of the API includes an HTTP code and the requested data formatted with JSON. If the request had any errors, the API will return an HTTP error code with an error message.

##Troubleshooting & support

These are some common errors you might find and how to go about them.

###Support
If you have other errors, or don't know how to proceed, please don't hesitate to contact us at support@babeliumproject.com describing your problem. Please provide the following data in your e-mail so that we can give you a better answer:
* A copy of your `babelium.log` file (placed in the root of your Moodle site's `moodledata` folder)
* Version of your browser. You can usually find it in the Help or About areas (e.g. __Mozilla Firefox 19.0.2__)
* Version of Flash Player Plugin. You can find it in the `about:plugins.` section of your browser (e.g. __Shockwave Flash 11.6 r602 PPAPI (out-of-process)__)
* Moodle version. You can find it in `<moodle_directory>/version.php` (e.g. `$release  = '2.2.7+ (Build: 20130222)'`)
* The Babelium Moodle plugin version. You can find it in `<moodle_directory>/mod/assignment/type/babelium/version.php` or `<moodle_directory>/mod/assign/submissions/babelium/version.php` (e.g. `$plugin->release = '0.9.6 (Build: 2012090600)'`);


###Babelium Error 403. Wrong authorization credentials
* Check the lengths of the key-set you were given. The access key should be 20 characters long and the private access key should be 40 characters long.

* Check the time of your server against a public time server. The timestamp of the requests to the Babelium server is checked to minimize request replication and we drop the requests that are too skewed. Different timezones are supported but you should be within a +/- 15 minute boundary relative to the actual time.

* If your key-set and server time are correct, perhaps you have a problem with the domain of your Moodle site. Please check the logs to see if the requests are coming from the expected domain.

* If your server is behind a load balancer or reverse proxy that uses a different IP address from the one defined in the DNS records for your domain/subdomain you will need to put the actual request IP (when registering for an API key the IP address is retrieved from the DNS record of the specified domain) in your Babelium database.

###Babelium Error 400. Malformed request error
* Take a look at the log file of `ZendRestJson.php` (by default it is placed in `/tmp/moodle.log`) to see if you have a permission issue in your Babelium file system.

###Babelium Error 500. Internal server error
* Very unlikely to occur. Could happen when the Babelium server uses an old version of PHP (&lt; PHP 5.0)

###Moodle server is behind a firewall
Babelium uses cURL to retrieve data from its API. If your Babelium instance is hosted in a different server than Moodle's and the Moodle server is behind a proxy/firewall you'll need to configure Moodle's proxy settings to have access to the data (the Babelium plugin will inherit these settings). To change these settings go to:

	Administration → Site administration → Server → HTTP
	
Fill in the data for your web proxy and remember to add the domain of your Babelium instance to the `Proxy bypass hosts` field.
