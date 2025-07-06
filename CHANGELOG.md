# Changelog


## [Unreleased]


## [1.3.1] - 2025-07-06
	- Feature: Quick edit

## [1.3.0] - 2025-05-26
	- Breaking changes: Allow Injection of container type to Assembler
	- Feature: Manage HasAssets to manage assets ( css, js )
	- Feature: Provide Project class
	- Feature: Provide Service Factory
	- Fix: Correct types to be compatible with php84 without warnings
	- Fix: Escape exception messages

## [1.2.1] - 2025-04-16

### Added
	- Allow filter to have a key property to be used when filtering
	- Allow filter to force to use key as value in meta filter
### Changed
	- Update filters to reflect that

## [1.2.0] - 2025-04-08

### Added
	- New hook in Admin Filters
	- Allow meta query params to be passed by model
	- Allow models to overwrite sort type
	- CS and analyzers dependencies
	- Set possible prefixes

### Changed
	- Fix admin filters
	- Improve naming of filters and actions
	- CS, Docs, and optimizations
	- Update admin cols from ext-cpts and small optimizations
	- Change action name for drag and drop to be more descriptive
	- Improve naming of Duplicate nonce and action
	- Simplify parsing of meta box setttings for registering in rest api

### Removed
	- CMB2Meta
	- Demo feature

## [1.1.4] - 2025-02-18

### Changed
	- Move loading codestar from files to classmap

## [1.1.3] - 2025-01-28

### Added
	- Add changelog

## [1.1.2] - 2024-12-10

### Fixed
	- Load models by ascending filename order

## [1.1.1] - 2024-12-10

### Fixed
	- Correct Media meta field type
	- Rename drag and drop action
	- Allow settings meta boxes to have any number of sections

## [1.1.0] - 2024-11-28

### Added
	- Items as CPTs
### Changed
	- Updated Codestar
	- Updated dependencies
### Fixed
	- Save linebreaks on save meta
	- interfaces usage
### Removed
	- p2p integration


## [1.0.2] - 2023-10-20

### Added
	- Remember tabs
### Fixed
	- Fix missing properties
	- Update features to be Processable


## [1.0.1] - 2022-04-20

### Added
	- Meta feature
	- Settings feature
	- Drag and drop feature
	- Duplicate feature
	- Export feature
	- Admin Cols and Admin Filters features

## [1.0.0] - 2019-04-23
	- Initial Release


The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
