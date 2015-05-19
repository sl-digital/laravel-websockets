<?php namespace Hlpartners\Sockets;

use Illuminate\Support\ServiceProvider;

class SocketsServiceProvider extends ServiceProvider 
{
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->package('hlpartners/sockets');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->registerHlServer();
	    $this->registerCommands();
	}

	private function registerHlServer()
	{
		$this->app['hlserver'] = $this->app->share(function($app)
		{
			$server = new SocketServer($app);
			return $server;
		});
	}

	private function registerCommands()
	{
		$this->app['command.hlserver.listen'] = $this->app->share(function($app)
		{
			return new ListenCommand($app);
		});

		$this->commands(
			'command.hlserver.listen'
		);
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('hlpartners');
	}

}
