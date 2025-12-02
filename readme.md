# Video PHP

A PHP application designed to handle video processing tasks by leveraging static FFmpeg binaries.

## Overview

This project provides a structure for working with video files using PHP. It includes a mechanism to automatically detect the system architecture and utilize the appropriate static FFmpeg and FFprobe binaries for video manipulation and analysis.

## Features

- **Architecture Detection**: Automatically detects the operating system architecture (e.g., x86_64, arm64, armv7l) to select the compatible binary.
- **Static Binaries**: Designed to work with self-contained FFmpeg binaries, reducing the need for system-wide FFmpeg installation.

## Requirements

- PHP 8.5
- Lando (recommended for local development)
- Composer

## Getting Started

### Installation

1**Install dependencies**:
   ```bash
   composer require simp/video-php
   ```