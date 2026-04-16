"""eUICC Simulator configuration via environment variables."""

from pathlib import Path
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    euicc_certs_dir: str = "./certs"
    smdp_address: str = "smdpplus.connectxiot.com"
    eim_fqdn: str = "eim.connectxiot.com"
    create_test_data: bool = True
    database_url: str = "sqlite:///./euicc_simulator.db"
    log_level: str = "info"
    host: str = "127.0.0.1"
    port: int = 8100

    model_config = {"env_file": ".env", "env_file_encoding": "utf-8"}


settings = Settings()
