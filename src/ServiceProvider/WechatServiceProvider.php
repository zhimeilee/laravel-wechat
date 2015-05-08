<?php namespace Zhimei\LaravelWechat\ServiceProvider;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Zhimei\LaravelWechat\Wechat;

class WechatServiceProvider extends LaravelServiceProvider {

	/**
     * 指定是否延缓提供者加载。
     *
     * @var bool
     */
    protected $defer = true;

	/**
	 * Bootstrap the application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		//$this->package('zhimei/wechat');
	}

	/**
	 * Register the application services.
	 *
	 * @return void
	 */
	public function register()
	{

		$this->app->singleton('wechat', function($app)
        {
            return Wechat::make($app['config']->get('wechat', []));
        });
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('wechat');
	}

}
