"""IPA Simulator configuration via environment variables."""

from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    euicc_simulator_url: str = "http://127.0.0.1:8100"
    eim_url: str = "https://eim.connectxiot.com"
    smdp_url: str = "https://smdpplus.connectxiot.com"
    database_url: str = "sqlite:///./ipa_simulator.db"
    log_level: str = "info"
    host: str = "127.0.0.1"
    port: int = 8101

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()
