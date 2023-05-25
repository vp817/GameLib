<?php

/**
 *   .oooooo.                                          ooooo         o8o   .o8
 *  d8P'  `Y8b                                         `888'         `"'  "888
 * 888            .oooo.   ooo. .oo.  .oo.    .ooooo.   888         oooo   888oooo.
 * 888           `P  )88b  `888P"Y88bP"Y88b  d88' `88b  888         `888   d88' `88b
 * 888     ooooo  .oP"888   888   888   888  888ooo888  888          888   888   888
 * `88.    .88'  d8(  888   888   888   888  888    .o  888       o  888   888   888
 *  `Y8bood8P'   `Y888""8o o888o o888o o888o `Y8bod8P' o888ooooood8 o888o  `Y8bod8P'
 * 
 * @author vp817, Laith98Dev
 * 
 * Copyright (C) 2023  vp817
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace vp817\GameLib;

use Closure;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginLogger;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\world\WorldManager;
use poggit\libasynql\DataConnector;
use poggit\libasynql\SqlError;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use TypeError;
use vp817\GameLib\arena\Arena;
use vp817\GameLib\arena\ArenaDataParser;
use vp817\GameLib\arena\message\ArenaMessages;
use vp817\GameLib\arena\message\DefaultArenaMessages;
use vp817\GameLib\arena\states\ArenaStates;
use vp817\GameLib\event\listener\DefaultArenaListener;
use vp817\GameLib\managers\ArenasManager;
use vp817\GameLib\managers\SetupManager;
use vp817\GameLib\managers\WaterdogManager;
use vp817\GameLib\player\SetupPlayer;
use vp817\GameLib\utilities\SqlQueries;
use vp817\GameLib\utilities\Utils;
use function basename;
use function count;
use function mkdir;
use function is_dir;
use function json_encode;
use function is_null;
use function strlen;
use function strtolower;
use function trim;
use const DIRECTORY_SEPARATOR;

final class GameLib
{

	/** @var null|PluginBase $plugin */
	private static ?PluginBase $plugin = null;
	/** @var ?DataConnector $plugin */
	private static ?DataConnector $database = null;
	/** @var ArenasManager $arenasManager */
	private ArenasManager $arenasManager;
	/** @var ArenaMessages $arenaMessages */
	private ArenaMessages $arenaMessages;
	/** @var string $arenaListenerClass */
	private string $arenaListenerClass;
	/** @var string $arenasBackupPath */
	private string $arenasBackupPath;
	/** @var SetupManager $setupManager */
	private SetupManager $setupManager;
	/** @var WaterdogManager $waterdogManager */
	private WaterdogManager $waterdogManager;

	/**
	 * initialize a new gamelib
	 * 
	 * sqlDatabase usage example:
	 * 
	 * sqlite: [ "type" => "sqlite" ]
	 * 
	 * mysql: [
	 * 		"type" => "mysql",
	 * 		"host" => "127.0.0.1",
	 * 		"username" => "root",
	 * 		"password" => "",
	 * 		"schema" => "schema"
	 * ]
	 * 
	 * waterdogData usage example:
	 * 
	 * example for not using:
	 * 
	 * duel: ["enabled" => false]
	 * 
	 * example for using:
	 * 
	 * duel: [
	 * 		"enabled" => true,
	 * 		"settings" => [
	 * 			"mode" => "simple",
	 * 			"lobby" => "127.0.0.1:19133"
	 * 		]
	 * ]
	 * 
	 * @param PluginBase $plugin
	 * @param array $sqlDatabase
	 * @param array $waterdogData
	 * @return GameLib
	 * @throws RuntimeException
	 */
	public static function init(PluginBase $plugin, array $sqlDatabase, array $waterdogData = ["enabled" => false]): GameLib
	{
		if (self::$plugin !== null) {
			throw new RuntimeException("GameLib is already initialized for this plugin");
		}

		if (!class_exists("poggit\libasynql\libasynql")) {
			throw new RuntimeException("libasyql virion not found. unable to use gamelib");
		}

		return new GameLib($plugin, $sqlDatabase, $waterdogData);
	}

	/**
	 * @return void
	 */
	public static function uninit(): void
	{
		if (isset(self::$database)) {
			self::$database->close();
		}
	}

	/**
	 * @param PluginBase $plugin
	 * @param array $waterdogData
	 * @param array $sqlDatabase
	 */
	public function __construct(PluginBase $plugin, array $sqlDatabase = [], array $waterdogData = [])
	{
		self::$plugin = $plugin;

		$sqlType = $sqlDatabase["type"];
		$database = [
			"type" => $sqlType
		];

		if ($sqlType == "sqlite") {
			$database["sqlite"] = [
				"file" => "data.sql"
			];
		} else if ($sqlType == "mysql") {
			$database["mysql"] = [
				"host" => $sqlDatabase["host"],
				"username" => $sqlDatabase["username"],
				"password" => $sqlDatabase["password"],
				"schema" => $sqlDatabase["schema"]
			];
		}

		$sqlMapPath = $plugin->getDataFolder() . "SqlMap";
		if (!is_dir($sqlMapPath)) {
			@mkdir($sqlMapPath);
		}

		foreach (glob(Path::join($this->getResourcesPath(), "*.sql")) as $resource) {
			$filename = basename($resource);
			Utils::saveResourceToPlugin($plugin, $this->getResourcesPath(), $filename, $sqlMapPath);
		}

		self::$database = Utils::libasynqlCreateForVirion($plugin, $database, [
			"sqlite" => Path::join($sqlMapPath, "sqlite.sql"),
			"mysql" => Path::join($sqlMapPath, "mysql.sql")
		]);

		self::$database->executeGeneric(SqlQueries::INIT, [], null, static function(SqlError $error) use ($plugin): void {
			$plugin->getLogger()->error($error->getMessage());
		});

		self::$database->waitAll();

		$this->arenasManager = new ArenasManager();
		$this->arenaMessages = new DefaultArenaMessages();
		$this->setupManager = new SetupManager();
		$this->waterdogManager = new WaterdogManager($waterdogData);
		$this->arenaListenerClass = DefaultArenaListener::class;
	}

	/**
	 * @return string
	 */
	public function getResourcesPath(): string
	{
		return __DIR__ . "/../../../resources" . DIRECTORY_SEPARATOR;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function setArenasBackupPath(string $path): void
	{
		if (!is_dir($path)) {
			@mkdir($path);
		}

		$this->arenasBackupPath = $path . DIRECTORY_SEPARATOR;
	}

	/**
	 * @internal
	 * @return WorldManager
	 */
	public function getWorldManager(): WorldManager
	{
		return self::$plugin->getServer()->getWorldManager();
	}

	/**
	 * @internal
	 * @return TaskScheduler
	 */
	public function getScheduler(): TaskScheduler
	{
		return self::$plugin->getScheduler();
	}

	/**
	 * @internal
	 * @return PluginLogger
	 */
	public function getLogger(): PluginLogger
	{
		return self::$plugin->getLogger();
	}

	/**
	 * @param ArenaMessages $arenaMessages
	 * @return void
	 */
	public function setArenaMessagesClass(ArenaMessages $arenaMessages): void
	{
		$this->arenaMessages = $arenaMessages;
	}

	/**
	 * @param string $arenaListener
	 * @return void
	 */
	public function setArenaListenerClass(string $arenaListener): void
	{
		$this->arenaListenerClass = $arenaListener;
	}

	/**
	 * @return ArenasManager
	 */
	public function getArenasManager(): ArenasManager
	{
		return $this->arenasManager;
	}

	/**
	 * @return string
	 */
	public function getArenasBackupPath(): string
	{
		return $this->arenasBackupPath;
	}

	/**
	 * @return ArenaMessages
	 */
	public function getArenaMessagesClass(): ArenaMessages
	{
		return $this->arenaMessages;
	}

	/**
	 * @return string
	 */
	public function getArenaListenerClass(): string
	{
		return $this->arenaListenerClass;
	}

	/**
	 * @return SetupManager
	 */
	public function getSetupManager(): SetupManager
	{
		return $this->setupManager;
	}

	/**
	 * @return WaterdogManager
	 */
	public function getWaterdogManager(): WaterdogManager
	{
		return $this->waterdogManager;
	}

	/**
	 * @param Arena $arena
	 * @return void
	 */
	public function registerArenaListener(Arena $arena): void
	{
		$class = $this->arenaListenerClass;
		if (strlen(trim($class)) < 1) {
			return;
		}

		$listener = null;
		try {
			$listener = new $class(self::$plugin, $this, $arena);
		} catch (TypeError $error) {
			$listener = null;
		}

		if ($listener === null) {
			return;
		}

		self::$plugin->getServer()->getPluginManager()->registerEvents($listener, self::$plugin);
	}

	/**
	 * @param Closure $onSuccess
	 * @param Closure $onFail
	 * @return void
	 */
	public function loadArenas(?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		self::$database->executeSelect(SqlQueries::GET_ALL_ARENAS, [], function($rows) use ($onSuccess, $onFail): void {
			if (count($rows) < 1) {
				if (!is_null($onFail)) {
					$onFail("None", "no arenas to be loaded");
				}
				return;
			}

			foreach ($rows as $arenasData) {
				$arenaID = $arenasData["arenaID"];
				$this->arenaExistsInDB($arenaID, function(bool $arenaExists) use ($arenaID, $arenasData, $onSuccess, $onFail): void {
					if (!$arenaExists) {
						if (!is_null($onFail)) {
							$onFail($arenaID, "Arena doesnt exists in db. this shouldnt happen");
						}
						return;
					}
					if ($this->getArenasManager()->hasLoadedArena($arenaID)) {
						if (!is_null($onFail)) {
							$onFail($arenaID, "unable to load an already loaded arena");
						}
						return;
					}

					$this->getArenasManager()->signAsLoaded($arenaID, new Arena($this, new ArenaDataParser($arenasData)), function($arena) use ($onSuccess): void {
						if (!is_null($onSuccess)) {
							$onSuccess($arena);
						}
					});
				});
			}
		});
	}

	/**
	 * @param string $arenaID
	 * @param Closure $onSuccess
	 * @param Closure $onFail
	 * @return void
	 */
	public function loadArena(string $arenaID, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$this->arenaExistsInDB($arenaID, function($arenaExists) use ($arenaID, $onSuccess, $onFail): void {
			if (!$arenaExists) {
				if (!is_null($onFail)) {
					$onFail();
				}
				return;
			}

			self::$database->executeSelect(SqlQueries::GET_ARENA_DATA, ["arenaID" => $arenaID], function($rows) use ($onSuccess, $onFail): void {
				if (count($rows) < 1) {
					if (!is_null($onFail)) {
						$onFail();
					}
					return;
				}

				foreach ($rows as $arenaData) {
					$arenaID = $arenaData["arenaID"];

					if (!array_key_exists("lobbySettings", $arenaData)) $arenaData["lobbySettings"] = json_encode([]);
					if (!array_key_exists("spawns", $arenaData)) $arenaData["spawns"] = json_encode([]);
					if (!array_key_exists("arenaData", $arenaData)) $arenaData["arenaData"] = json_encode([]);
					if (!array_key_exists("extraData", $arenaData)) $arenaData["extraData"] = json_encode([]);

					$this->getArenasManager()->signAsLoaded($arenaID, new Arena($this, new ArenaDataParser($arenaData)), function($arena) use ($onSuccess): void {
						if (!is_null($onSuccess)) {
							$onSuccess($arena);
						}
					});
				}
			});
		});
	}

	/**
	 * @param string $arenaID
	 * @param string $worldName
	 * @param string $mode
	 * @param int $countdownTime
	 * @param int $arenaTime
	 * @param int $restartingTime
	 * @param Closure $onSuccess
	 * @param Closure $onFail
	 * @return void
	 */
	public function createArena(string $arenaID, string $worldName, string $mode, int $countdownTime, int $arenaTime, int $restartingTime, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$this->arenaExistsInDB($arenaID, function(bool $arenaExists) use ($arenaID, $worldName, $mode, $countdownTime, $arenaTime, $restartingTime, $onSuccess, $onFail): void {
			if ($arenaExists) {
				if (!is_null($onFail)) {
					$onFail($arenaID, "Arena already exists");
				}
				return;
			}

			$data = [
				"arenaID" => $arenaID,
				"worldName" => $worldName,
				"mode" => $mode,
				"countdownTime" => $countdownTime,
				"arenaTime" => $arenaTime,
				"restartingTime" => $restartingTime
			];

			self::$database->executeInsert(SqlQueries::ADD_ARENA, $data);

			if (!is_null($onSuccess)) {
				$onSuccess($data);
			}
		});
	}

	/**
	 * @param string $arenaID
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @param bool $alertConsole
	 * @return void
	 */
	public function removeArena(string $arenaID, ?Closure $onSuccess = null, ?Closure $onFail = null, bool $alertConsole = true): void
	{
		$this->arenaExistsInDB($arenaID, function($arenaExists) use ($arenaID, $onSuccess, $onFail, $alertConsole): void {
			if (!$arenaExists) {
				$reason = "Arena does not exists";
				if (!is_null($onFail)) {
					$onFail($arenaID, $reason);
				}
				return;
			}

			$arenasManager = $this->getArenasManager();

			self::$database->executeChange(SqlQueries::REMOVE_ARENA, ["arenaID" => $arenaID], function($rows) use ($arenasManager, $arenaID, $onSuccess, $alertConsole): void {
				if ($arenasManager->hasLoadedArena($arenaID)) {
					$arenasManager->unsignFromBeingLoaded($arenaID, function() use ($arenaID, $onSuccess): void {
						if (!is_null($onSuccess)) {
							$onSuccess($arenaID);
						}
					});
				}

				if ($alertConsole) {
					self::$plugin->getLogger()->alert("Arena: $arenaID has been successfully removed");
				}
			});
		});
	}

	/**
	 * @param string $arenaID
	 * @return void
	 */
	public function arenaExistsInDB(string $arenaID, Closure $valueCallback): void
	{
		self::$database->executeSelect(SqlQueries::GET_ALL_ARENAS, [], function($rows) use ($valueCallback, $arenaID): void {
			foreach ($rows as $arenasData) {
				if (strtolower($arenasData["arenaID"]) === strtolower($arenaID)) {
					$valueCallback(true);
					return;
				}
			}
			$valueCallback(false);
		});
	}

	/**
	 * @param Player $player
	 * @param string $arenaID
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @return void
	 */
	public function addPlayerToSetupArena(Player $player, string $arenaID, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$this->arenaExistsInDB($arenaID, function($arenaExists) use ($player, $arenaID, $onSuccess, $onFail): void {
			if (!$arenaExists) {
				if (!is_null($onFail)) {
					$onFail($arenaID, "arena doesnt exists in db");
				}
				return;
			}

			if ($this->getArenasManager()->hasLoadedArena($arenaID)) {
				if (!is_null($onFail)) {
					$onFail($arenaID, "unable to add player to setup a loaded arena");
				}
				return;
			}

			$this->getSetupManager()->add($player, $arenaID, function(SetupPlayer $player) use ($onSuccess): void {
				if (!is_null($onSuccess)) {
					$onSuccess($player);
				}
			}, function() use ($arenaID, $onFail): void {
				if (!is_null($onFail)) {
					$onFail($arenaID, "You are already inside the setup");
				}
			});
		});
	}

	/**
	 * @param Player $player
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @return void
	 */
	public function finishArenaSetup(Player $player, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$setupManager = $this->getSetupManager();
		if (!$setupManager->has($player->getUniqueId()->getBytes())) {
			if (!is_null($onFail)) {
				$onFail("The player is not inside the setup");
			}
			return;
		}

		$setupManager->get($player->getUniqueId()->getBytes(), function(SetupPlayer $setupPlayer) use ($setupManager, $onSuccess, $onFail): void {
			$setupSettings = $setupPlayer->getSetupSettings();
			$arenaID = $setupPlayer->getSetupingArenaID();

			$fail = function(SqlError $error) use ($onFail): void {
				if (!is_null($onFail)) {
					$onFail($error->getMessage());
				}
			};

			self::$database->executeChange(SqlQueries::UPDATE_ARENA_SPAWNS, ["arenaID" => $arenaID, "spawns" => json_encode($setupSettings->getSpawns())], null, $fail);
			self::$database->executeChange(SqlQueries::UPDATE_ARENA_LOBBY_SETTINGS, ["arenaID" => $arenaID, "settings" => $setupSettings->getLobbySettings()], null, $fail);
			self::$database->executeChange(SqlQueries::UPDATE_ARENA_DATA, ["arenaID" => $arenaID, "arenaData" => $setupSettings->getArenaData()], null, $fail);

			if ($setupSettings->hasExtraData()) {
				self::$database->executeChange(SqlQueries::UPDATE_ARENA_EXTRA_DATA, ["arenaID" => $arenaID, "extraData" => $setupSettings->getExtraData()], null, $fail);
			}

			$setupSettings->clear();

			$this->loadArena($arenaID, function(Arena $arena) use ($onSuccess): void {
				if (!is_null($onSuccess)) {
					$onSuccess($arena);
				}
			}, function() use ($onFail): void {
				if (!is_null($onFail)) {
					$onFail("unable to load arena");
				}
			});

			$setupManager->remove($setupPlayer->getCells());
		});
	}

	/**
	 * @param Player $player
	 * @param string $arenaID
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @return void
	 */
	public function joinArena(Player $player, string $arenaID, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$this->getArenasManager()->getLoadedArena($arenaID, function(Arena $arena) use ($player, $onSuccess): void {
			$arena->join($player, function() use ($onSuccess, $arena): void {
				if (!is_null($onSuccess)) {
					$onSuccess($arena);
				}
			});
		}, $onFail);
	}

	/**
	 * @param Player $player
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @return void
	 */
	public function joinRandomArena(Player $player, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$arenasManager = $this->getArenasManager();
		$arenaMessages = $this->getArenaMessagesClass();

		$sortedArenas = [];
		foreach ($arenasManager->getAll() as $gameID => $arena) {
			$mode = $arena->getMode();

			if (array_key_exists($player->getUniqueId()->getBytes(), $mode->getPlayers())) {
				if (!is_null($onFail)) {
					$onFail($arenaMessages->PlayerAlreadyInsideAnArena());
				}
				return;
			}
			$sortedArenas[$mode->getPlayerCount()] = $arena;
		}
		ksort($sortedArenas);

		if (empty($sortedArenas)) {
			if (!is_null($onFail)) {
				$onFail($arenaMessages->NoArenasFound());
			}
			return;
		}

		$closedArenas = array_filter($sortedArenas, function(Arena $value) {
			return (!$value->getState()->equals(ArenaStates::WAITING()) || !$value->getState()->equals(ArenaStates::COUNTDOWN())) && $value->getMode()->getPlayerCount() > $value->getMode()->getMaxPlayers() - 1;
		});
		$openedArenas = array_filter($sortedArenas, function(Arena $value) {
			return ($value->getState()->equals(ArenaStates::WAITING()) || $value->getState()->equals(ArenaStates::COUNTDOWN())) && $value->getMode()->getPlayerCount() > $value->getMode()->getMaxPlayers() - 1;
		});

		$plannedArena = $openedArenas[array_key_last($openedArenas)];
		if (empty($openedArenas) || in_array($plannedArena, $closedArenas, true)) {
			if (!is_null($onFail)) {
				$onFail($arenaMessages->NoArenasFound());
			}
			return;
		}

		if (count($openedArenas) >= 2) {
			foreach ($openedArenas as $key => $value) {
				$plannedArenaMode = $plannedArena->getMode();
				$valueMode = $value->getMode();

				if ($plannedArenaMode->getPlayerCount() < $valueMode->getMaxPlayers()) {
					$plannedArena = $value;
				} elseif ($plannedArenaMode->getPlayerCount() === $valueMode->getMaxPlayers()) {
					$plannedArena = $openedArenas[mt_rand((count($openedArenas) - count($openedArenas)) + 1, (count($openedArenas) + count($openedArenas)) - 1) % count($openedArenas)];
				} elseif ($plannedArenaMode->getPlayerCount() === 0 && $valueMode->getMaxPlayers() === 0) {
					$plannedArena = $openedArenas[array_rand($openedArenas)];
				}
			}
		}

		$plannedArena->sendPlayerToArena($player);

		if (!is_null($onSuccess)) {
			$onSuccess($plannedArena);
		}
	}

	/**
	 * @param Player $player
	 * @param null|Closure $onSuccess
	 * @param null|Closure $onFail
	 * @return void
	 */
	public function leaveArena(Player $player, ?Closure $onSuccess = null, ?Closure $onFail = null): void
	{
		$arenasManager = $this->getArenasManager();
		foreach ($arenasManager->getAll() as $arenaID => $value) {
			if ($value->getMode()->hasPlayer($player->getUniqueId()->getBytes())) {
				$value->quit($player, function() use ($onSuccess, $arenaID): void {
					if (!is_null($onSuccess)) {
						$onSuccess($arenaID);
					}
				});
				break;
			}
		}
		if (!is_null($onFail)) {
			$onFail();
		}
	}
}
