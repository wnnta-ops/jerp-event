import 'package:flutter/material.dart';

/// Base URL API FO. Override saat run/build:
/// `--dart-define=API_BASE_URL=https://domain/api/mobile`
const apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://localhost:8000/api/mobile',
);

const oranye = Color(0xFFF97316);
