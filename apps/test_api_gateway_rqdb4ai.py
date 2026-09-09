import os
import unittest
from unittest.mock import patch

from fastapi import HTTPException

os.environ.setdefault("PDF_BASE_URL", "http://192.168.0.3:8010/pdf")

import api_gateway


class ApiGatewayRQDB4AITest(unittest.TestCase):
    def test_missing_token_disables_direct_generation_fallback(self):
        with patch.object(api_gateway, "RQDB4AI_TOKEN", ""):
            with self.assertRaises(HTTPException) as raised:
                api_gateway.rqdb4ai_headers()
        self.assertEqual(raised.exception.status_code, 503)

    def test_auth_header_is_bearer_token(self):
        with patch.object(api_gateway, "RQDB4AI_TOKEN", "secret"):
            headers = api_gateway.rqdb4ai_headers()
        self.assertEqual(headers["Authorization"], "Bearer secret")

    def test_ernie_resource_metadata_follows_configured_base_url(self):
        self.assertEqual(api_gateway.ERNIE_RESOURCE_HOST, "192.168.0.11")
        self.assertEqual(api_gateway.ERNIE_RESOURCE_PORT, 18300)


if __name__ == "__main__":
    unittest.main()
